<?php

namespace App\Services\Billing;

use App\Services\Promotions\Promotion;
use Spark\Facades\DB;
use function count;

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
                // Serialize source-period removal with promotion settlement and staff holds.
                Promotion::transaction(function () use ($customer, $cutoff): void {
                    $subscriptions = DB::table('billing_subscriptions')->where('customer_id', $customer->id);
                    if ($subscriptions->whereNotNull('active_key')->exists()) {
                        return;
                    }

                    $ids = DB::table('billing_subscriptions')->where('customer_id', $customer->id)->pluck('id');
                    $invoices = $ids ? DB::table('billing_invoices')->whereIn('subscription_id', $ids)->pluck('id') : [];
                    if (
                        $invoices && DB::table('billing_adjustments')->whereIn('invoice_id', $invoices)
                            ->where(fn($query) => $query->where('allocation', 'review_required')->orWhere('updated_at', '>=', $cutoff))->exists()
                    ) {
                        return;
                    }

                    if (
                        $invoices && (
                            DB::table('billing_paid_periods')->whereIn('invoice_id', $invoices)->where('promotion_hold', true)->exists()
                            || DB::table('promotion_reservations as r')
                                ->join('promotion_boosts as b', 'b.id', '=', 'r.boost_id')
                                ->join('promotion_credits as c', 'c.id', '=', 'r.credit_id')
                                ->join('billing_paid_periods as p', 'p.id', '=', 'c.period_id')
                                ->whereIn('p.invoice_id', $invoices)
                                ->where(fn($query) => $query->whereNull('b.settled_at')->orWhere('b.staff_hold', true))->exists()
                        )
                    ) {
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
