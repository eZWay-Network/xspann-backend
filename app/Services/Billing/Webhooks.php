<?php

namespace App\Services\Billing;

use App\Jobs\ProcessBillingWebhook;
use Spark\Facades\{Cache, DB, Hash};

class Webhooks
{
    public function __construct(private PaymentProvider $provider, private Reconciliation $reconciliation)
    {
    }

    public function receive(string $body, string $signature): void
    {
        $event = $this->provider->verifyWebhook($body, $signature);
        DB::table('billing_webhooks')->upsert([
            'provider' => 'stripe', 'environment' => config('billing.environment'),
            'event_id' => $event['id'], 'type' => $event['type'], 'payload' => Hash::encrypt($body),
            'created_at' => Billing::timestamp(), 'updated_at' => Billing::timestamp(),
        ], ['provider', 'environment', 'event_id'], ['event_id']);
        self::dispatch(Billing::table('webhooks')->where('event_id', $event['id'])->first()->id);
    }

    public static function dispatch(int $id): void
    {
        $claimed = Billing::table('webhooks')->where('id', $id)->whereIn('status', ['pending', 'failed'])
            ->update(['status' => 'queued', 'updated_at' => Billing::timestamp()]);
        if (!$claimed) {
            return;
        }
        try {
            ProcessBillingWebhook::dispatch($id)->onQueue('billing')->send();
        } catch (\Throwable) {
            // Receipt is already durable. Recovery retries even if the broker is unavailable.
            DB::table('billing_webhooks')->where('id', $id)->update(['status' => 'pending', 'updated_at' => Billing::timestamp()]);
        }
    }

    public function process(int $id): void
    {
        Cache::lock("billing.webhook.$id", 300)->block(10, function () use ($id): void {
            $record = Billing::table('webhooks')->where('id', $id)->first();
            if (!$record || in_array($record->status, ['processed', 'ignored'], true)) {
                return;
            }
            DB::table('billing_webhooks')->where('id', $id)->update([
                'status' => 'processing', 'attempts' => $record->attempts + 1, 'updated_at' => Billing::timestamp(),
            ]);
            try {
                $event = json_decode(Hash::decrypt($record->payload), true, flags: JSON_THROW_ON_ERROR);
                $applied = $this->apply($event);
                DB::table('billing_webhooks')->where('id', $id)->update([
                    'status' => $applied ? 'processed' : 'ignored', 'error_code' => null,
                    'processed_at' => Billing::timestamp(), 'updated_at' => Billing::timestamp(),
                ]);
            } catch (\Throwable) {
                DB::table('billing_webhooks')->where('id', $id)->update([
                    'status' => 'failed', 'error_code' => 'reconciliation_required', 'updated_at' => Billing::timestamp(),
                ]);
                // Provider objects/bodies must never enter queue logs or failed-job exceptions.
                throw new \RuntimeException('Billing webhook reconciliation failed; inspect the local inbox record ' . $id . '.');
            }
        });
    }

    private function apply(array $event): bool
    {
        $remote = [];
        $type = $event['type'];
        $id = $event['data']['object']['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_]+$/D', $id)) {
            return false;
        }
        $checkoutEvent = in_array($type, ['checkout.session.completed', 'checkout.session.expired'], true);
        $invoiceEvent = in_array($type, ['invoice.paid', 'invoice.payment_failed', 'invoice.payment_action_required'], true);
        $subscriptionEvent = in_array($type, ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'], true);
        $refundEvent = in_array($type, ['refund.created', 'refund.updated', 'refund.failed', 'charge.refund.updated', 'charge.refunded'], true);
        $disputeEvent = in_array($type, ['charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed'], true);
        if (!$checkoutEvent && !$invoiceEvent && !$subscriptionEvent && !$refundEvent && !$disputeEvent) {
            return false;
        }
        Billing::assertAccount($this->provider);
        if ($checkoutEvent) {
            $remote = $this->provider->retrieveCheckout($id);
            $attempt = Billing::table('checkouts')->where('reference', $remote['client_reference_id'] ?? '')->first();
        } else {
            if ($refundEvent || $disputeEvent) {
                $adjustment = $type === 'charge.refunded' ? null : $this->provider->request('GET', ($disputeEvent ? '/disputes/' : '/refunds/') . $id);
                $charge = $this->provider->request('GET', '/charges/' . ($adjustment['charge'] ?? $id));
                if (!is_string($charge['invoice'] ?? null)) {
                    return false;
                }
                $remote = $this->provider->request('GET', '/invoices/' . $charge['invoice']);
            } elseif ($invoiceEvent) {
                $remote = $this->provider->request('GET', '/invoices/' . $id);
            }
            $remoteSubscription = $subscriptionEvent ? $this->provider->retrieveSubscription($id)
                : (is_string($remote['subscription'] ?? null) ? $this->provider->retrieveSubscription($remote['subscription']) : null);
            if (!$remoteSubscription) {
                return false;
            }
            $attempt = $this->reconciliation->attemptForSubscription($remoteSubscription);
        }
        if (!$attempt) {
            return false;
        }

        return Billing::locked($attempt->user_id ?? -$attempt->customer_id, function () use ($attempt, $id, $checkoutEvent, $invoiceEvent, $subscriptionEvent, $refundEvent, $disputeEvent, $type, $remote): bool {
            // Retrieve again after locking: old event snapshots are never an ordering authority.
            $subscription = $checkoutEvent
                ? $this->reconciliation->checkout($attempt, $this->provider->retrieveCheckout($id), validateOffer: false)
                : $this->reconciliation->recoverCheckout($attempt, validateOffer: false);
            if (!$subscription) {
                if (!$checkoutEvent) {
                    throw new \RuntimeException('Waiting for the locally initiated Checkout.');
                }
                return true;
            }
            $owner = $subscription->user_id ? \App\Models\User::find($subscription->user_id) : null;
            if ($subscription->deletion_requested_at || !$owner || $owner->status === 'deleting') {
                app(SubscriptionActions::class)->stopForDeletion($subscription);
            }
            if ($invoiceEvent) {
                $this->reconciliation->invoice($subscription, $id);
            } elseif ($checkoutEvent || $subscriptionEvent) {
                $this->reconciliation->invoices($subscription);
            } else {
                $adjustment = $type === 'charge.refunded' ? null : [
                    'kind' => $disputeEvent ? 'dispute' : 'refund',
                    'object' => $this->provider->request('GET', ($disputeEvent ? '/disputes/' : '/refunds/') . $id),
                ];
                $this->reconciliation->invoice($subscription, $remote['id'], $adjustment);
            }

            return true;
        });
    }
}
