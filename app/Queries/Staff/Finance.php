<?php

namespace App\Queries\Staff;

use App\Services\Billing\Billing;
use App\Services\Staff\{Context, Sessions};
use Spark\Facades\DB;

final class Finance
{
    public static function read(Context $context, array $range): array
    {
        $context->authorize('analytics.finance.view');
        $until = (new \DateTimeImmutable($range['to'], new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');
        $from = $range['from'] . ' 00:00:00';
        // Each invoice is counted once. Joining adjustments here would multiply money.
        $invoices = Billing::table('invoices')->where('status', 'paid')->where('issued_at', '>=', $from)->where('issued_at', '<', $until)
            ->selectRaw('currency, COUNT(*) AS invoice_count, SUM(amount_paid) AS amount_paid')->groupBy('currency')->orderBy('currency')->get();
        $adjustments = Billing::table('adjustments')->where('created_at', '>=', $from)->where('created_at', '<', $until)
            ->selectRaw('currency, kind, status, allocation, COUNT(*) AS adjustment_count, SUM(amount) AS amount')
            ->groupBy(['currency', 'kind', 'status', 'allocation'])->orderByRaw('currency, kind, status, allocation')->get();
        $paid = self::paidAccess()->selectRaw('c.plan_code, COUNT(*) AS accounts')->groupBy('c.plan_code')->orderBy('c.plan_code')->get();
        $gaps = Billing::table('subscriptions')->where(function ($query): void {
            $query->whereNull('reconciled_at')->orWhere('reconciled_at', '<', Billing::timestamp(time() - 86400));
        })->whereNull('deletion_requested_at')->count();

        return [
            'environment' => config('billing.environment'),
            'invoices' => array_map(fn ($row) => ['currency' => strtoupper($row->currency), 'count' => (int) $row->invoice_count, 'amount_paid' => (int) $row->amount_paid], $invoices->all()),
            'adjustments' => array_map(fn ($row) => ['currency' => strtoupper($row->currency), 'kind' => $row->kind, 'status' => $row->status,
                'allocation' => $row->allocation, 'count' => (int) $row->adjustment_count, 'amount' => (int) $row->amount], $adjustments->all()),
            'paid_access' => array_map(fn ($row) => ['plan' => $row->plan_code, 'accounts' => (int) $row->accounts], $paid->all()),
            'reconciliation_gaps' => $gaps,
            'generated_at' => Sessions::iso(Sessions::timestamp()),
            'data_since' => Billing::date(Billing::table('invoices')->selectRaw('MIN(issued_at) AS earliest')->first()?->earliest),
            'bounds' => ['from' => Billing::date($from), 'until' => Billing::date($until)],
            'definition' => 'Tax-inclusive paid invoices issued in the selected UTC range, by currency. These are retained invoice totals, not net revenue or settlement-date receipts. Adjustments are shown separately by current state and local creation date.',
            'comparison' => null,
        ];
    }

    public static function paidAccess(): \Spark\Database\QueryBuilder
    {
        $periods = db()->wrapTable('billing_paid_periods');
        $subscriptions = db()->wrapTable('billing_subscriptions');
        // Match Billing::access: choose the last-ending unrevoked current period per active owner.
        return DB::table('billing_paid_periods as p')
            ->join('billing_subscriptions as s', 's.id', '=', 'p.subscription_id')
            ->join('billing_catalog as c', 'c.id', '=', 's.catalog_id')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->where('s.environment', config('billing.environment'))->where('u.status', 'active')->whereNull('s.deletion_requested_at')
            ->whereNull('p.revoked_at')->where('p.starts_at', '<=', Billing::timestamp())->where('p.ends_at', '>', Billing::timestamp())
            ->whereRaw("NOT EXISTS (SELECT 1 FROM $periods p2 JOIN $subscriptions s2 ON s2.id = p2.subscription_id
                WHERE s2.user_id = s.user_id AND s2.environment = s.environment AND s2.deletion_requested_at IS NULL
                AND p2.revoked_at IS NULL AND p2.starts_at <= :access_now AND p2.ends_at > :access_end
                AND (p2.ends_at > p.ends_at OR (p2.ends_at = p.ends_at AND p2.id > p.id)))",
                ['access_now' => Billing::timestamp(), 'access_end' => Billing::timestamp()]);
    }
}
