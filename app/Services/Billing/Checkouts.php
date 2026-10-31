<?php

namespace App\Services\Billing;

use App\Services\Artists;
use App\Models\User;
use Spark\Facades\{DB, Hash};
use function is_string;

class Checkouts
{
    public function __construct(private PaymentProvider $provider)
    {
    }

    public function create(User $user, array $input, string $key): object
    {
        abort_unless(
            preg_match('/^[\x21-\x7e]{8,200}$/D', $key),
            422,
            'Supply an Idempotency-Key of 8–200 visible ASCII characters.'
        );

        $hash = hash('sha256', json_encode([$input['plan_code'], $input['offer_version']], JSON_THROW_ON_ERROR));

        return Billing::locked($user->id, function () use ($user, $input, $key, $hash): object {
            Billing::activeUser($user->id);

            $attempt = DB::table('billing_checkouts')->where('user_id', $user->id)
                ->where('idempotency_hash', hash('sha256', $key))->first();

            if ($attempt) {
                abort_unless(
                    hash_equals($attempt->request_hash, $hash)
                    && $attempt->environment === config('billing.environment'),
                    409,
                    'Idempotency key was used for a different request.'
                );
                return $this->completeRequest($attempt);
            }

            Artists::owned($user);

            abort_if(
                config('billing.environment') === 'live' && !config('billing.live_sales_enabled'),
                503,
                'Live sales are disabled until the paid benefits and sales checks are complete.'
            );

            abort_unless(
                $input['offer_version'] === config('billing.offer_version'),
                422,
                'Accept the current offer version.'
            );

            $plan = config('billing.plans.' . $input['plan_code']);
            abort_unless(
                $plan && is_string($plan['price_id']) && preg_match('/^price_[a-zA-Z0-9_]+$/D', $plan['price_id']),
                503,
                'This offer is not configured.'
            );

            Billing::assertAccount($this->provider);
            $price = $this->provider->request('GET', '/prices/' . $plan['price_id']);
            Billing::assertEnvironment($price);

            abort_unless(
                ($price['id'] ?? null) === $plan['price_id'] && ($price['active'] ?? false)
                && ($price['unit_amount'] ?? null) === $plan['amount'] && ($price['currency'] ?? null) === 'usd'
                && ($price['recurring']['interval'] ?? null) === 'month'
                && ($price['recurring']['interval_count'] ?? null) === 1
                && ($price['recurring']['usage_type'] ?? null) === 'licensed',
                503,
                'Provider price does not match the offer.'
            );

            $return = Billing::returnUrl('return_url');
            $cancel = Billing::returnUrl('cancel_url');
            $attempt = DB::transaction(function () use ($user, $input, $key, $hash, $plan, $return, $cancel): object {
                User::increment('id', 0, where: ['id' => $user->id]);
                Billing::activeUser($user->id);

                abort_if(
                    DB::table('billing_checkouts')->where('active_key', (string) $user->id)->exists()
                    || DB::table('billing_subscriptions')->where('active_key', (string) $user->id)->exists()
                    || Billing::access($user->id),
                    409,
                    'An active subscription or pending Checkout already exists.'
                );

                Billing::table('catalog')->upsert([
                    'provider' => 'stripe',
                    'environment' => config('billing.environment'),
                    'plan_code' => $input['plan_code'],
                    'offer_version' => $input['offer_version'],
                    'price_id' => $plan['price_id'],
                    'amount' => $plan['amount'],
                    'created_at' => Billing::timestamp(),
                    'updated_at' => Billing::timestamp(),
                ], ['provider', 'environment', 'plan_code', 'offer_version'], ['offer_version']);

                $catalog = Billing::table('catalog')->where('plan_code', $input['plan_code'])->where('offer_version', $input['offer_version'])->first();
                abort_unless(
                    $catalog->price_id === $plan['price_id'] && $catalog->amount === $plan['amount'],
                    503,
                    'Change the offer version before changing a price mapping.'
                );
                $customer = Billing::table('customers')->where('user_id', $user->id)->first();
                if (!$customer) {
                    $id = DB::table('billing_customers')->insert([
                        'user_id' => $user->id,
                        'provider' => 'stripe',
                        'environment' => config('billing.environment'),
                        'reference' => bin2hex(random_bytes(20)),
                        'created_at' => Billing::timestamp(),
                        'updated_at' => Billing::timestamp(),
                    ]);

                    $customer = DB::table('billing_customers')->where('id', $id)->first();
                }

                $reference = bin2hex(random_bytes(20));
                $id = DB::table('billing_checkouts')->insert([
                    'user_id' => $user->id,
                    'customer_id' => $customer->id,
                    'catalog_id' => $catalog->id,
                    'provider' => 'stripe',
                    'environment' => config('billing.environment'),
                    'reference' => $reference,
                    'idempotency_hash' => hash('sha256', $key),
                    'request_hash' => $hash,
                    'active_key' => (string) $user->id,
                    'payload' => Hash::encrypt(json_encode([
                        'mode' => 'subscription',
                        'payment_method_types' => ['card'],
                        'allow_promotion_codes' => 'false',
                        'customer_update' => ['address' => 'auto'],
                        'success_url' => $return . (str_contains($return, '?') ? '&' : '?') . 'reference=' . $reference,
                        'cancel_url' => $cancel,
                        'client_reference_id' => $reference,
                        'metadata' => ['xpn_checkout' => $reference],
                        'subscription_data' => ['metadata' => ['xpn_checkout' => $reference]],
                        'line_items' => [['price' => $catalog->price_id, 'quantity' => 1]],
                        'automatic_tax' => ['enabled' => config('billing.automatic_tax') ? 'true' : 'false'],
                    ], JSON_THROW_ON_ERROR)),
                    'created_at' => Billing::timestamp(),
                    'updated_at' => Billing::timestamp(),
                ]);

                return DB::table('billing_checkouts')->where('id', $id)->first();
            });

            return $this->completeRequest($attempt);
        });
    }

    private function completeRequest(object $attempt): object
    {
        if ($attempt->session_id || $attempt->status !== 'pending') {
            return $attempt;
        }
        Billing::activeUser($attempt->user_id);
        abort_if(
            $attempt->environment === 'live' && !config('billing.live_sales_enabled'),
            503,
            'Live sales are disabled.'
        );
        Billing::assertAccount($this->provider);

        $customer = DB::table('billing_customers')->where('id', $attempt->customer_id)->first();
        if (!$customer->customer_id && $customer->request_started_at && strtotime($customer->request_started_at . ' UTC') < time() - 23 * 3600) {
            $customer = app(Reconciliation::class)->recoverCustomer($customer);
        }
        if (!$customer->customer_id) {
            // Stripe may discard keys after 24 hours. Never replay an uncertain POST beyond that window.
            abort_if(
                $customer->request_started_at && strtotime($customer->request_started_at . ' UTC') < time() - 23 * 3600,
                409,
                'Customer creation needs operator reconciliation; no second customer was created.'
            );

            DB::table('billing_customers')->where('id', $customer->id)->whereNull('request_started_at')
                ->update(['request_started_at' => Billing::timestamp()]);

            $remote = $this->provider->request('POST', '/customers', ['metadata' => ['xpn_customer' => $customer->reference]], 'xpn-customer-' . $customer->reference);
            Billing::assertEnvironment($remote);
            abort_unless(
                is_string($remote['id'] ?? null) && ($remote['metadata']['xpn_customer'] ?? null) === $customer->reference,
                409,
                'Customer mismatch.'
            );
            DB::table('billing_customers')->where('id', $customer->id)->update(['customer_id' => $remote['id'], 'updated_at' => Billing::timestamp()]);
            $customer->customer_id = $remote['id'];
        }

        abort_if(
            $attempt->request_started_at && strtotime($attempt->request_started_at . ' UTC') < time() - 23 * 3600,
            409,
            'Checkout creation needs operator reconciliation; no second session was created.'
        );

        $payload = json_decode(Hash::decrypt($attempt->payload), true, flags: JSON_THROW_ON_ERROR);
        $payload['customer'] = $customer->customer_id;
        DB::table('billing_checkouts')->where('id', $attempt->id)->whereNull('request_started_at')
            ->update(['request_started_at' => Billing::timestamp()]);

        $remote = $this->provider->createCheckout($payload, 'xpn-checkout-' . $attempt->reference);
        Billing::assertEnvironment($remote);

        abort_unless(
            is_string($remote['id'] ?? null) && ($remote['customer'] ?? null) === $customer->customer_id
            && ($remote['client_reference_id'] ?? null) === $attempt->reference
            && ($remote['mode'] ?? null) === 'subscription' && is_int($remote['expires_at'] ?? null),
            409,
            'Checkout ownership mismatch.'
        );

        $url = Billing::hostedUrl($remote['url'] ?? null, 'checkout.stripe.com');
        abort_unless(
            (bool) $url,
            503,
            'Invalid hosted Checkout URL.'
        );

        DB::table('billing_checkouts')->where('id', $attempt->id)->update([
            'session_id' => $remote['id'],
            'url' => $url,
            'expires_at' => Billing::timestamp($remote['expires_at']),
            'updated_at' => Billing::timestamp(),
        ]);

        return DB::table('billing_checkouts')->where('id', $attempt->id)->first();
    }

    public static function data(object $attempt): array
    {
        $access = $attempt->user_id ? Billing::access($attempt->user_id) : null;

        return [
            'reference' => $attempt->reference,
            'status' => $attempt->status,
            'url' => $attempt->status === 'pending' ? $attempt->url : null,
            'expires_at' => Billing::date($attempt->expires_at),
            'access_active' => $access !== null,
            'paid_through' => Billing::date($access?->ends_at),
        ];
    }
}
