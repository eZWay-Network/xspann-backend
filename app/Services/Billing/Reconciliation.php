<?php

namespace App\Services\Billing;

use Spark\Facades\DB;
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;

/** All callers serialize using Billing::locked for the locally established owner. */
class Reconciliation
{
    public function __construct(private PaymentProvider $provider)
    {
    }

    public function checkout(object $attempt, ?array $remote = null, bool $validateOffer = true): ?object
    {
        $attempt = $this->freshAttempt($attempt);
        if (!$remote && !$attempt->session_id) {
            return null;
        }

        $remote ??= $this->provider->retrieveCheckout($attempt->session_id);
        Billing::assertEnvironment($remote);

        $customer = DB::table('billing_customers')->where('id', $attempt->customer_id)->first();
        $catalog = DB::table('billing_catalog')->where('id', $attempt->catalog_id)->first();

        $lines = $remote['line_items']['data'] ?? [];
        abort_unless(
            ($remote['customer'] ?? null) === $customer->customer_id && $customer->customer_id
            && ($remote['client_reference_id'] ?? null) === $attempt->reference
            && (!$attempt->session_id || ($remote['id'] ?? null) === $attempt->session_id)
            && ($remote['mode'] ?? null) === 'subscription' && count($lines) === 1
            && empty($remote['line_items']['has_more']) && ($lines[0]['price']['id'] ?? null) === $catalog->price_id
            && ($lines[0]['quantity'] ?? null) === 1
            && is_int($remote['expires_at'] ?? null),
            409,
            'Checkout does not match the recorded purchase.'
        );

        $status = match ($remote['status'] ?? '') {
            'open' => 'pending', 'complete' => 'completed', 'expired' => 'expired',
            default => throw new \RuntimeException('Unknown Checkout status.'),
        };

        DB::table('billing_checkouts')->where('id', $attempt->id)->update([
            'session_id' => $remote['id'],
            'status' => $status,
            'active_key' => $status === 'expired' ? null : $attempt->active_key,
            'url' => $status === 'pending' ? Billing::hostedUrl($remote['url'] ?? null, 'checkout.stripe.com') : null,
            'expires_at' => Billing::timestamp($remote['expires_at']),
            'updated_at' => Billing::timestamp(),
        ]);
        if ($status !== 'completed' || !is_string($remote['subscription'] ?? null)) {
            return null;
        }

        $subscription = $this->provider->retrieveSubscription($remote['subscription']);

        return $this->subscription($attempt, $subscription, $validateOffer);
    }

    public function subscription(object $attempt, array $remote, bool $validateOffer = true): object
    {
        $attempt = $this->freshAttempt($attempt);
        Billing::assertEnvironment($remote);

        $customer = DB::table('billing_customers')->where('id', $attempt->customer_id)->first();
        $catalog = DB::table('billing_catalog')->where('id', $attempt->catalog_id)->first();
        $items = $remote['items']['data'] ?? [];

        abort_unless(
            ($remote['customer'] ?? null) === $customer->customer_id
            && is_string($remote['id'] ?? null)
            && is_int($remote['current_period_end'] ?? null)
            && in_array($remote['status'] ?? null, ['incomplete', 'incomplete_expired', 'trialing', 'active', 'past_due', 'canceled', 'unpaid', 'paused'], true),
            409,
            'Invalid subscription state.'
        );

        abort_if(
            $validateOffer && !(count($items) === 1 && empty($remote['items']['has_more'])
                && ($items[0]['price']['id'] ?? null) === $catalog->price_id
                && ($items[0]['quantity'] ?? null) === 1
                && empty($remote['trial_end']) && empty($remote['discount'])
                && $remote['status'] !== 'trialing'),
            409,
            'Subscription does not match the recorded offer.'
        );

        // The provider Checkout, not metadata, must establish this exact subscription.
        $checkout = $this->provider->retrieveCheckout($attempt->session_id ?? DB::table('billing_checkouts')->where('id', $attempt->id)->value('session_id'));
        Billing::assertEnvironment($checkout);

        abort_unless(
            ($checkout['subscription'] ?? null) === ($remote['id'] ?? null)
            && ($checkout['customer'] ?? null) === $customer->customer_id
            && ($checkout['client_reference_id'] ?? null) === $attempt->reference,
            409,
            'Subscription is not owned by this Checkout.'
        );

        return DB::transaction(function () use ($attempt, $remote): object {
            if ($attempt->user_id) {
                \App\Models\User::increment('id', 0, where: ['id' => $attempt->user_id]);
            }
            $attempt = $this->freshAttempt($attempt);
            $owner = $attempt->user_id ? \App\Models\User::find($attempt->user_id) : null;

            $existing = Billing::table('subscriptions')->where('subscription_id', $remote['id'])->first();
            abort_if(
                $existing && $existing->checkout_id !== $attempt->id,
                409,
                'Subscription already belongs to another purchase.'
            );

            $ended = in_array($remote['status'], ['canceled', 'incomplete_expired'], true);
            $data = [
                'status' => $remote['status'],
                'cancel_at_period_end' => (bool) ($remote['cancel_at_period_end'] ?? false),
                'period_end' => Billing::timestamp($remote['current_period_end']),
                'active_key' => $ended || !$attempt->user_id ? null : (string) $attempt->user_id,
                'reconciled_at' => Billing::timestamp(),
                'updated_at' => Billing::timestamp(),
            ];

            if (!$owner || $owner->status === 'deleting') {
                $data['deletion_requested_at'] = $existing?->deletion_requested_at ?? Billing::timestamp();
            }
            if ($existing) {
                DB::table('billing_subscriptions')->where('id', $existing->id)->update($data);
                $id = $existing->id;
            } else {
                $id = DB::table('billing_subscriptions')->insert([
                    ...$data,
                    'user_id' => $attempt->user_id,
                    'checkout_id' => $attempt->id,
                    'customer_id' => $attempt->customer_id,
                    'catalog_id' => $attempt->catalog_id,
                    'provider' => 'stripe',
                    'environment' => config('billing.environment'),
                    'subscription_id' => $remote['id'],
                    'created_at' => Billing::timestamp(),
                ]);
            }

            DB::table('billing_checkouts')->where('id', $attempt->id)->update([
                'status' => 'completed',
                'active_key' => null,
                'url' => null,
                'payload' => null,
                'updated_at' => Billing::timestamp(),
            ]);

            return DB::table('billing_subscriptions')->where('id', $id)->first();
        });
    }

    /** Locate only; callers must still retrieve and validate the locally owned Checkout. */
    public function attemptForSubscription(array $remote): ?object
    {
        $known = Billing::table('subscriptions')->where('subscription_id', $remote['id'] ?? '')->first();
        $reference = $remote['metadata']['xpn_checkout'] ?? null;
        if (!$known && !is_string($reference)) {
            return null;
        }

        $attempt = $known ? Billing::table('checkouts')->where('id', $known->checkout_id)->first()
            : Billing::table('checkouts')->where('reference', $reference)->first();

        if (!$attempt) {
            return null;
        }
        $customer = DB::table('billing_customers')->where('id', $attempt->customer_id)->first();
        if (!$customer->customer_id || $customer->customer_id !== ($remote['customer'] ?? null)) {
            return null;
        }

        return $attempt;
    }

    public function invoice(object $subscription, string $invoiceId, ?array $eventAdjustment = null): void
    {
        $invoice = $this->provider->request('GET', '/invoices/' . rawurlencode($invoiceId));
        Billing::assertEnvironment($invoice);

        $customer = DB::table('billing_customers')->where('id', $subscription->customer_id)->first();
        $catalog = DB::table('billing_catalog')->where('id', $subscription->catalog_id)->first();
        $lines = $invoice['lines']['data'] ?? [];

        abort_unless(
            ($invoice['id'] ?? null) === $invoiceId
            && ($invoice['subscription'] ?? null) === $subscription->subscription_id
            && ($invoice['customer'] ?? null) === $customer->customer_id
            && ($invoice['currency'] ?? null) === $catalog->currency
            && count($lines) === 1 && empty($invoice['lines']['has_more'])
            && ($lines[0]['type'] ?? null) === 'subscription'
            && ($lines[0]['subscription'] ?? null) === $subscription->subscription_id
            && ($lines[0]['price']['id'] ?? null) === $catalog->price_id
            && ($lines[0]['quantity'] ?? null) === 1 && empty($lines[0]['proration'])
            && ($lines[0]['amount'] ?? null) === $catalog->amount
            && empty($invoice['discounts']) && empty($invoice['total_discount_amounts'])
            && in_array($invoice['billing_reason'] ?? null, ['subscription_create', 'subscription_cycle'], true)
            && is_int($invoice['amount_paid'] ?? null) && $invoice['amount_paid'] >= 0
            && is_int($invoice['amount_due'] ?? null) && $invoice['amount_due'] >= 0
            && is_int($invoice['created'] ?? null),
            409,
            'Invoice does not match the subscription.'
        );

        $start = $lines[0]['period']['start'] ?? null;
        $end = $lines[0]['period']['end'] ?? null;
        abort_unless(
            is_int($start) && is_int($end) && $start > 0 && $end > $start
            && $end - $start <= 32 * 86400,
            409,
            'Invalid monthly service period.'
        );

        $paid = ($invoice['status'] ?? null) === 'paid';
        if ($paid) {
            abort_unless(
                ($invoice['paid'] ?? false) && empty($invoice['paid_out_of_band'])
                && ($invoice['amount_remaining'] ?? null) === 0
                && $invoice['amount_paid'] >= $catalog->amount
                && $invoice['amount_paid'] === $invoice['amount_due']
                && is_int($invoice['status_transitions']['paid_at'] ?? null),
                409,
                'Invoice has not been provider settled.'
            );
        }

        $charge = null;
        $adjustments = [];
        if ($paid) {
            $charge = app(Adjustments::class)->settlement($invoice, $customer->customer_id);
            foreach (['refund', 'dispute'] as $kind) {
                $adjustments[$kind] = $this->chargeAdjustments($kind, $charge['id']);
            }
            if ($eventAdjustment) {
                // The list was retrieved later; an earlier GET must not overwrite its newer status.
                $adjustments[$eventAdjustment['kind']][$eventAdjustment['object']['id']] ??= $eventAdjustment['object'];
            }
            $refundTotal = array_sum(array_map(fn($refund) => in_array($refund['status'] ?? '', ['failed', 'canceled'], true)
                ? 0 : ($refund['amount'] ?? 0), $adjustments['refund']));

            abort_if(($charge['amount_refunded'] ?? 0) > $refundTotal, 503, 'Waiting for charge refunds.');
            abort_if(!empty($charge['disputed']) && !$adjustments['dispute'], 503, 'Waiting for charge disputes.');
        }

        // Settlement, revocations and promotion holds become visible together.
        DB::transaction(function () use ($subscription, $invoice, $catalog, $start, $end, $paid, $charge, $adjustments): void {
            if ($subscription->user_id) {
                \App\Models\User::increment('id', 0, where: ['id' => $subscription->user_id]);
            }
            DB::table('billing_invoices')->upsert([
                'subscription_id' => $subscription->id,
                'provider' => 'stripe',
                'environment' => config('billing.environment'),
                'invoice_id' => $invoice['id'],
                'charge_id' => is_string($invoice['charge'] ?? null) ? $invoice['charge'] : null,
                'status' => $invoice['status'],
                'amount_paid' => $invoice['amount_paid'],
                'amount_due' => $invoice['amount_due'],
                'currency' => $invoice['currency'],
                'hosted_url' => Billing::hostedUrl($invoice['hosted_invoice_url'] ?? null, 'invoice.stripe.com'),
                'issued_at' => Billing::timestamp($invoice['created']),
                'created_at' => Billing::timestamp(),
                'updated_at' => Billing::timestamp(),
            ], ['provider', 'environment', 'invoice_id'], ['status', 'amount_paid', 'amount_due', 'hosted_url', 'charge_id', 'updated_at']);

            $record = Billing::table('invoices')->where('invoice_id', $invoice['id'])->first();
            abort_unless(
                $record->subscription_id === $subscription->id,
                409,
                'Invoice ownership conflict.'
            );

            if ($paid) {
                $existing = DB::table('billing_paid_periods')->where('subscription_id', $subscription->id)
                    ->where('starts_at', Billing::timestamp($start))->where('ends_at', Billing::timestamp($end))->first();

                abort_if(
                    $existing && $existing->invoice_id !== $record->id,
                    409,
                    'This service period is already recorded against another invoice.'
                );

                // Replay never clears a refund or dispute hold, or issues another monthly grant.
                DB::table('billing_paid_periods')->upsert([
                    'subscription_id' => $subscription->id,
                    'invoice_id' => $record->id,
                    'starts_at' => Billing::timestamp($start),
                    'ends_at' => Billing::timestamp($end),
                    'boost_credit_cents' => $catalog->plan_code === 'artist_pro' ? 500 : 0,
                    'created_at' => Billing::timestamp(),
                    'updated_at' => Billing::timestamp(),
                ], ['invoice_id'], ['invoice_id']);
                if ($subscription->user_id && !$existing) {
                    \App\Services\Realtime::record($subscription->user_id, 'entitlements.updated', $subscription->user_id, 0);
                }
                foreach ($adjustments as $kind => $objects) {
                    foreach ($objects as $object) {
                        app(Adjustments::class)->apply($record, $kind, $object, $charge);
                    }
                }
            }
        });
    }

    private function chargeAdjustments(string $kind, string $chargeId): array
    {
        $objects = [];
        $cursor = null;
        $seen = [];
        do {
            $page = $this->provider->request('GET', '/' . $kind . 's', array_filter([
                'charge' => $chargeId,
                'limit' => 100,
                'starting_after' => $cursor,
            ]));

            abort_unless(is_array($page['data'] ?? null), 503, 'Invalid adjustment recovery response.');
            foreach ($page['data'] as $object) {
                abort_unless(is_string($object['id'] ?? null), 503, 'Invalid adjustment identifier.');
                $objects[$object['id']] = $object;
            }

            $next = $page['data'] ? end($page['data'])['id'] : null;
            abort_if(
                !empty($page['has_more']) && (!$next || isset($seen[$next])),
                503,
                'Invalid adjustment recovery cursor.'
            );

            if ($next) {
                $seen[$next] = true;
            }
            $cursor = $next;
        } while (!empty($page['has_more']));

        return $objects;
    }

    private function freshAttempt(object $attempt): object
    {
        $current = Billing::table('checkouts')->where('id', $attempt->id)->first();
        abort_unless((bool) $current, 409, 'Checkout is no longer retained.');

        return $current;
    }

    public function recoverCheckout(object $attempt, bool $validateOffer = true): ?object
    {
        $attempt = $this->freshAttempt($attempt);
        if ($attempt->session_id) {
            return $this->checkout($attempt, validateOffer: $validateOffer);
        }
        $customer = DB::table('billing_customers')->where('id', $attempt->customer_id)->first();
        if (!$customer->customer_id && $customer->request_started_at) {
            $customer = $this->recoverCustomer($customer);
        }
        if (!$customer->customer_id) {
            return null;
        }
        $cursor = null;
        do {
            $page = $this->provider->request('GET', '/checkout/sessions', array_filter([
                'customer' => $customer->customer_id,
                'limit' => 100,
                'starting_after' => $cursor,
            ]));
            abort_unless(is_array($page['data'] ?? null), 503, 'Invalid Checkout recovery response.');
            foreach ($page['data'] as $session) {
                if (($session['client_reference_id'] ?? null) === $attempt->reference) {
                    return $this->checkout($attempt, $this->provider->retrieveCheckout($session['id']), $validateOffer);
                }
            }
            $next = $page['data'] ? end($page['data'])['id'] : null;
            abort_if(!empty($page['has_more']) && (!$next || $next === $cursor), 503, 'Invalid Checkout recovery cursor.');
            $cursor = $next;
        } while (!empty($page['has_more']));

        return null;
    }

    public function recoverCustomer(object $customer): object
    {
        $cursor = null;
        do {
            $page = $this->provider->request('GET', '/customers', array_filter([
                'created' => ['gte' => strtotime($customer->request_started_at . ' UTC') - 60],
                'limit' => 100,
                'starting_after' => $cursor,
            ]));
            abort_unless(is_array($page['data'] ?? null), 503, 'Invalid customer recovery response.');
            foreach ($page['data'] as $remote) {
                if (($remote['metadata']['xpn_customer'] ?? null) !== $customer->reference) {
                    continue;
                }
                Billing::assertEnvironment($remote);
                DB::table('billing_customers')->where('id', $customer->id)->update([
                    'customer_id' => $remote['id'],
                    'updated_at' => Billing::timestamp(),
                ]);

                return DB::table('billing_customers')->where('id', $customer->id)->first();
            }
            $next = $page['data'] ? end($page['data'])['id'] : null;
            abort_if(!empty($page['has_more']) && (!$next || $next === $cursor), 503, 'Invalid customer recovery cursor.');
            $cursor = $next;
        } while (!empty($page['has_more']));

        return $customer;
    }

    public function invoices(object $subscription): void
    {
        $cursor = null;
        do {
            $page = $this->provider->request('GET', '/invoices', array_filter([
                'subscription' => $subscription->subscription_id,
                'limit' => 100,
                'starting_after' => $cursor,
            ]));
            abort_unless(is_array($page['data'] ?? null), 503, 'Invalid invoice recovery response.');
            foreach ($page['data'] as $invoice) {
                $this->invoice($subscription, $invoice['id']);
            }
            $next = $page['data'] ? end($page['data'])['id'] : null;
            abort_if(!empty($page['has_more']) && (!$next || $next === $cursor), 503, 'Invalid invoice recovery cursor.');
            $cursor = $next;
        } while (!empty($page['has_more']));
    }
}
