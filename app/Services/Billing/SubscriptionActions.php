<?php

namespace App\Services\Billing;

use Spark\Facades\DB;
use function in_array;
use function is_string;

class SubscriptionActions
{
    public function __construct(private PaymentProvider $provider, private Reconciliation $reconciliation)
    {
    }

    public function renewal(int $userId, bool $cancel): array
    {
        return Billing::locked($userId, function () use ($userId, $cancel): array {
            $cancel ? Billing::owner($userId) : Billing::activeUser($userId);
            Billing::assertAccount($this->provider);

            $subscription = Billing::subscription($userId);
            abort_unless((bool) $subscription, 404, 'Subscription not found.');

            $attempt = DB::table('billing_checkouts')->where('id', $subscription->checkout_id)->first();
            $subscription = $this->reconciliation->recoverCheckout($attempt, validateOffer: !$cancel);

            abort_unless((bool) $subscription, 409, 'Subscription is not linked.');
            if ($cancel && in_array($subscription->status, ['canceled', 'incomplete_expired'], true)) {
                return Billing::subscriptionData($userId);
            }

            abort_unless(
                !in_array($subscription->status, ['canceled', 'incomplete_expired'], true)
                && ($cancel || strtotime($subscription->period_end . ' UTC') > time()),
                409,
                'An ended subscription cannot be resumed.'
            );

            abort_if((bool) $subscription->deletion_requested_at, 409, 'Account deletion has stopped billing changes.');
            if ($subscription->renewal_operation) {
                abort_unless(
                    (bool) $subscription->renewal_target === $cancel,
                    409,
                    'A different renewal change is still pending. Retry it first.'
                );
            } elseif ((bool) $subscription->cancel_at_period_end === $cancel) {
                return Billing::subscriptionData($userId);
            } else {
                $subscription->renewal_operation = bin2hex(random_bytes(20));
                DB::table('billing_subscriptions')->where('id', $subscription->id)->update([
                    'renewal_operation' => $subscription->renewal_operation,
                    'renewal_target' => $cancel,
                    'renewal_started_at' => Billing::timestamp(),
                    'updated_at' => Billing::timestamp(),
                ]);
            }
            // A current provider read resolves a lost response, even after Stripe's key expires.
            if ((bool) $subscription->cancel_at_period_end !== $cancel) {
                abort_if(
                    $subscription->renewal_started_at && strtotime($subscription->renewal_started_at . ' UTC') < time() - 23 * 3600,
                    409,
                    'Renewal change requires reconciliation.'
                );

                $remote = $this->provider->setRenewal($subscription->subscription_id, $cancel, 'xpn-renewal-' . $subscription->renewal_operation);
                abort_unless(
                    ($remote['id'] ?? null) === $subscription->subscription_id
                    && ($remote['cancel_at_period_end'] ?? null) === $cancel,
                    409,
                    'Renewal change has not been confirmed.'
                );

                $this->reconciliation->subscription($attempt, $remote, validateOffer: !$cancel);
            }

            DB::table('billing_subscriptions')->where('id', $subscription->id)->update([
                'renewal_operation' => null,
                'renewal_target' => null,
                'renewal_started_at' => null,
            ]);

            return Billing::subscriptionData($userId);
        });
    }

    public function portal(int $userId): string
    {
        return Billing::locked($userId, function () use ($userId): string {
            Billing::activeUser($userId);
            Billing::assertAccount($this->provider);

            $customer = Billing::table('customers')->where('user_id', $userId)->first();
            abort_unless($customer?->customer_id && !$customer->deleted_at, 404, 'Billing customer not found.');

            $configuration = config('billing.portal_configuration');
            abort_unless(is_string($configuration) && preg_match('/^bpc_[a-zA-Z0-9]+$/D', $configuration), 503, 'Configure the billing portal.');

            $remote = $this->provider->request('GET', "/billing_portal/configurations/$configuration");
            Billing::assertEnvironment($remote);

            abort_unless(
                ($remote['id'] ?? null) === $configuration && ($remote['active'] ?? false)
                && ($remote['features']['subscription_update']['enabled'] ?? null) === false
                && empty($remote['features']['subscription_pause']['enabled'])
                && (empty($remote['features']['subscription_cancel']['enabled'])
                    || ($remote['features']['subscription_cancel']['mode'] ?? null) === 'at_period_end')
                && empty($remote['features']['subscription_cancel']['retention']),
                503,
                'Portal must disable plan switching, pauses and retention offers, with cancellation at period end only.'
            );

            $result = $this->provider->createPortal($customer->customer_id, $configuration, Billing::returnUrl('portal_return_url'));
            $url = Billing::hostedUrl($result['url'] ?? null, 'billing.stripe.com');

            abort_unless((bool) $url, 503, 'Invalid billing portal URL.');

            return $url;
        });
    }

    /** Called under the billing account lock; provider deletion is retryable. */
    public function stopForDeletion(object $subscription): void
    {
        DB::table('billing_subscriptions')
            ->where('id', $subscription->id)
            ->update(['deletion_requested_at' => Billing::timestamp()]);

        $remote = $this->provider->retrieveSubscription($subscription->subscription_id);

        Billing::assertEnvironment($remote);
        abort_unless(
            ($remote['id'] ?? null) === $subscription->subscription_id,
            409,
            'Subscription ownership mismatch.'
        );
        if (!in_array($remote['status'] ?? '', ['canceled', 'incomplete_expired'], true)) {
            $remote = $this->provider->request('DELETE', '/subscriptions/' . $subscription->subscription_id, [], 'xpn-delete-sub-' . $subscription->id);
        }

        abort_unless(
            ($remote['id'] ?? null) === $subscription->subscription_id
            && in_array($remote['status'] ?? '', ['canceled', 'incomplete_expired'], true),
            503,
            'Waiting for renewal cancellation.'
        );

        DB::table('billing_subscriptions')->where('id', $subscription->id)->update([
            'status' => $remote['status'],
            'cancel_at_period_end' => true,
            'active_key' => null,
            'renewal_operation' => null,
            'renewal_target' => null,
            'renewal_started_at' => null,
            'updated_at' => Billing::timestamp(),
        ]);
    }

    public function deleteAccount(int $userId): void
    {
        Billing::locked($userId, function () use ($userId): void {
            $customers = DB::table('billing_customers')->where('user_id', $userId)->whereNull('deleted_at')->get();
            foreach ($customers as $customer) {
                abort_unless(
                    $customer->environment === config('billing.environment') && $customer->provider === 'stripe',
                    503,
                    'Switch billing environment to finish account billing cleanup.'
                );

                Billing::assertAccount($this->provider);
                if (!$customer->customer_id && $customer->request_started_at) {
                    $customer = $this->reconciliation->recoverCustomer($customer);
                }

                // An unknown customer POST outcome cannot be safely forgotten.
                abort_if(
                    !$customer->customer_id && $customer->request_started_at,
                    503,
                    'Recover pending customer creation before deleting billing records.'
                );

                foreach (DB::table('billing_checkouts')->where('customer_id', $customer->id)->whereNotNull('active_key')->get() as $attempt) {
                    $this->reconciliation->recoverCheckout($attempt, validateOffer: false);
                    $attempt = DB::table('billing_checkouts')->where('id', $attempt->id)->first();
                    if ($attempt->status === 'pending' && $attempt->session_id) {
                        $remote = $this->provider->request('POST', '/checkout/sessions/' . $attempt->session_id . '/expire', [], 'xpn-expire-' . $attempt->reference);
                        abort_unless(
                            ($remote['status'] ?? null) === 'expired',
                            503,
                            'Waiting for Checkout cancellation.'
                        );
                    }

                    // Deleting the provider customer below also disables unknown sessions for it.
                    DB::table('billing_checkouts')->where('id', $attempt->id)->update([
                        'status' => $attempt->status === 'completed' ? 'completed' : 'expired',
                        'active_key' => null,
                        'url' => null,
                        'payload' => null,
                        'updated_at' => Billing::timestamp(),
                    ]);
                }
                foreach (DB::table('billing_subscriptions')->where('customer_id', $customer->id)->get() as $subscription) {
                    $this->stopForDeletion($subscription);
                }
                if ($customer->customer_id) {
                    $remote = $this->provider->request('GET', '/customers/' . $customer->customer_id);
                    abort_unless(
                        ($remote['id'] ?? null) === $customer->customer_id,
                        503,
                        'Invalid billing customer response.'
                    );

                    if (empty($remote['deleted'])) {
                        Billing::assertEnvironment($remote);
                        $remote = $this->provider->request('DELETE', '/customers/' . $customer->customer_id, [], 'xpn-delete-customer-' . $customer->reference);
                    }

                    abort_unless(
                        ($remote['deleted'] ?? false) && ($remote['id'] ?? null) === $customer->customer_id,
                        503,
                        'Waiting for billing customer deletion.'
                    );
                }
                DB::table('billing_customers')->where('id', $customer->id)->update(['deleted_at' => Billing::timestamp(), 'updated_at' => Billing::timestamp()]);
            }
        });
    }
}
