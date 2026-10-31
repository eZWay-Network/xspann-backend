<?php

namespace App\Jobs;

use App\Services\Billing\{Billing, PaymentProvider, Reconciliation, SubscriptionActions, Webhooks};
use Spark\Facades\{DB, Log};
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;

class BillingMaintenance implements JobInterface
{
    use Dispatchable;

    public function handle(): void
    {
        foreach (Billing::table('webhooks')->where(function ($query): void {
            $query->where(fn($query) => $query->whereIn('status', ['pending', 'failed'])
                ->where('updated_at', '<', Billing::timestamp(time() - 60)))
                ->orWhere(fn($query) => $query->whereIn('status', ['queued', 'processing'])
                    ->where('updated_at', '<', Billing::timestamp(time() - 300)));
        })->where('attempts', '<', 10)
            ->orderByRaw('updated_at ASC, id ASC')->limit(100)->get() as $record) {
            Billing::table('webhooks')->where('id', $record->id)->update(['status' => 'pending']);
            Webhooks::dispatch($record->id);
        }

        foreach (Billing::table('checkouts')->where(function ($query): void {
            $query->where('status', 'pending')->orWhereIn('id', Billing::table('subscriptions')->select('checkout_id')
                ->where(fn($query) => $query->whereNotNull('active_key')
                    ->orWhereIn('id', DB::table('billing_paid_periods')->select('subscription_id')
                        ->where(fn($query) => $query->where('ends_at', '>', Billing::timestamp())->orWhere('boost_credit_cents', '>', 0)))));
        })->whereNotNull('user_id')->where('updated_at', '<', Billing::timestamp(time() - 300))
            ->orderByRaw('updated_at ASC, id ASC')->limit(50)->get() as $attempt) {
            try {
                Billing::locked($attempt->user_id, function () use ($attempt): void {
                    Billing::assertAccount(app(PaymentProvider::class));
                    $service = app(Reconciliation::class);
                    $subscription = $service->recoverCheckout($attempt, validateOffer: false);
                    if ($subscription) {
                        $owner = $subscription->user_id ? \App\Models\User::find($subscription->user_id) : null;
                        if ($subscription->deletion_requested_at || !$owner || $owner->status === 'deleting') {
                            app(SubscriptionActions::class)->stopForDeletion($subscription);
                        }
                    }
                });
                $current = Billing::table('subscriptions')->where('checkout_id', $attempt->id)->first();
                if ($current?->renewal_operation && !$current->deletion_requested_at) {
                    app(SubscriptionActions::class)->renewal($attempt->user_id, (bool) $current->renewal_target);
                }
                if ($current) {
                    Billing::locked($attempt->user_id, fn() => app(Reconciliation::class)->invoices($current));
                }
            } catch (\Throwable) {
                Log::error('Billing reconciliation requires attention for checkout {id}.', ['id' => $attempt->id]);
            } finally {
                DB::table('billing_checkouts')->where('id', $attempt->id)->update(['updated_at' => Billing::timestamp()]);
            }
        }

        Billing::table('webhooks')->whereIn('status', ['processed', 'ignored'])
            ->where('processed_at', '<', Billing::timestamp(time() - config('billing.webhook_retention_days', 30) * 86400))
            ->update(['payload' => null]);

        \App\Services\Billing\Retention::run();
    }
}
