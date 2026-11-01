<?php

namespace App\Http\Controllers\Staff\Promotions;

use App\Http\Requests\Staff\ListFilters;
use App\Http\Resources\Staff\Records;
use App\Services\Promotions\Promotion;
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class CreditController
{
    public function index(Request $request): Response
    {
        $filters = ListFilters::read($request, ['owner_id' => [], 'period_id' => []]);
        $credits = Promotion::table('credits')->select('id');
        if (!empty($filters['owner_id'])) {
            $credits->where('user_id', $filters['owner_id']);
        }
        if (!empty($filters['period_id'])) {
            $credits->where('period_id', $filters['period_id']);
        }
        $query = DB::table('promotion_ledger')->whereIn('credit_id', $credits);
        $totals = (clone $query)->selectRaw('COALESCE(SUM(available_delta), 0) AS available, COALESCE(SUM(reserved_delta), 0) AS reserved,
            COALESCE(SUM(CASE WHEN used_delta > 0 THEN used_delta ELSE 0 END), 0) AS charged,
            COALESCE(SUM(CASE WHEN used_delta < 0 THEN -used_delta ELSE 0 END), 0) AS returned')->first();
        if ($filters['q'] !== '') {
            $query->where('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0);
        }
        $query->select(['id', 'credit_id', 'boost_id', 'kind', 'available_delta', 'reserved_delta', 'used_delta', 'created_at'])
            ->selectSub(Promotion::table('credits')->select('period_id')->whereColumn('promotion_credits.id', 'promotion_ledger.credit_id')->limit(1), 'period_id');

        return Inertia::render('promotions/credits/index', Records::listing($query, $filters,
            fn ($row) => Records::row($row, Records::label($row->kind) . ' #' . $row->id,
                $row->boost_id ? (string) route('staff.boosts.show', ['boost' => $row->boost_id]) : null, $row->kind,
                'Credit #' . $row->credit_id . ' · Available ' . $row->available_delta . '¢ · Reserved ' . $row->reserved_delta . '¢ · Used ' . $row->used_delta . '¢', 'UTC') + [
                    'links' => $row->period_id ? [['label' => 'Source period #' . $row->period_id,
                        'url' => (string) route('staff.credits')->withQuery(['period_id' => $row->period_id])]] : [],
                ],
            'staff.credits', [], true) + [
                'page' => ['title' => 'Credit ledger', 'breadcrumbs' => []],
                'description' => 'Immutable entries in the current billing environment. Recorded balances may be held and are not an entitlement editor.',
                'balances' => ['available_cents' => (int) $totals->available, 'reserved_cents' => (int) $totals->reserved,
                    'charged_cents' => (int) $totals->charged, 'returned_cents' => (int) $totals->returned],
            ]);
    }
}
