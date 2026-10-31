<?php

namespace App\Services\Billing;

use Spark\Facades\DB;

class Retention
{
    public static function run(): void
    {
        $cutoff = Billing::timestamp(time() - max(1, (int) config('billing.retention_days', 2555)) * 86400);
        $lastId = 0;
        do {
            $customers = Billing::table('customers')->whereNull('user_id')->where('deleted_at', '<', $cutoff)
                ->where('id', '>', $lastId)->orderBy('id')->limit(100)->get();
            foreach ($customers as $customer) {
                $lastId = $customer->id;
                DB::transaction(function () use ($customer, $cutoff): void {
                    $subscriptions = DB::table('billing_subscriptions')->where('customer_id', $customer->id);
                    if ($subscriptions->whereNotNull('active_key')->exists()) {
                        return;
                    }
                    $ids = DB::table('billing_subscriptions')->where('customer_id', $customer->id)->pluck('id');
                    $invoices = $ids ? DB::table('billing_invoices')->whereIn('subscription_id', $ids)->pluck('id') : [];
                    if ($invoices && DB::table('billing_adjustments')->whereIn('invoice_id', $invoices)
                        ->where(fn($query) => $query->where('allocation', 'review_required')->orWhere('updated_at', '>=', $cutoff))->exists()) {
                        return;
                    }
                    if ($invoices) {
                        DB::table('billing_adjustments')->whereIn('invoice_id', $invoices)->delete();
                        DB::table('billing_paid_periods')->whereIn('invoice_id', $invoices)->delete();
                        DB::table('billing_invoices')->whereIn('id', $invoices)->delete();
                    }
                    DB::table('billing_subscriptions')->where('customer_id', $customer->id)->delete();
                    DB::table('billing_checkouts')->where('customer_id', $customer->id)->delete();
                    DB::table('billing_customers')->where('id', $customer->id)->delete();
                });
            }
        } while (count($customers) === 100);

        Billing::table('webhooks')->whereIn('status', ['processed', 'ignored'])->where('created_at', '<', $cutoff)->delete();
    }
}
