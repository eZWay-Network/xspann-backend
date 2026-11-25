<?php

namespace App\Http\Controllers\Staff;

use App\Queries\Staff\Overview;
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};
use Spark\Facades\DB;

final class DashboardController
{
    public function index(Request $request): Response
    {
        $context = Context::from($request);
        if (!$context->can('reports.view')) {
            return $this->analytics($request);
        }
        $queue = [
            ['title' => 'Open reports', 'count' => DB::table('reports')->whereIn('status', ['open', 'reviewing'])->count(), 'url' => (string) route('staff.reports.index')],
            ['title' => 'Awaiting review', 'count' => DB::table('reports')->where('status', 'open')->count(), 'url' => (string) route('staff.reports.index')->withQuery(['status' => 'open'])],
            ['title' => 'In review', 'count' => DB::table('reports')->where('status', 'reviewing')->count(), 'url' => (string) route('staff.reports.index')->withQuery(['status' => 'reviewing'])],
        ];
        if ($context->can('appeals.view')) {
            $queue[] = ['title' => 'Pending appeals', 'count' => DB::table('moderation_appeals')->where('status', 'open')->count(), 'url' => (string) route('staff.appeals.index')->withQuery(['status' => 'open'])];
        }
        $recent = [];
        foreach (['Accounts' => ['suspend', 'ban', 'soft_delete'], 'Content' => ['quarantine', 'restrict', 'remove', 'soft_delete']] as $title => $actions) {
            $query = DB::table('moderation_decisions')->whereIn('action', $actions);
            if ($title === 'Accounts') {
                $query->where('target_type', 'user');
            } else {
                $query->where('target_type', '!=', 'user');
            }
            $recent[] = ['title' => $title, 'items' => array_map(fn ($row) => [
                'id' => (int) $row->id, 'label' => ucfirst($row->action) . ' · ' . $row->target_type . ' #' . $row->target_id,
                'reason' => $row->reason, 'created_at' => \App\Http\Resources\Staff\Records::date($row->created_at),
                'url' => (string) route('staff.reports.show', ['report' => $row->report_id]),
            ], $query->orderBy('id', 'desc')->limit(10)->all())];
        }
        return Inertia::render('dashboard', ['page' => ['title' => 'Dashboard', 'breadcrumbs' => []], 'queues' => $queue, 'recent' => $recent]);
    }

    public function analytics(Request $request): Response
    {
        $context = Context::from($request);
        $range = Overview::range($request->all(), $context->preferences()['timezone']);

        return Inertia::render('overview', [
            'overview' => fn() => Overview::read($context, $range),
            'filters' => $range,
            'finance' => $context->can('analytics.finance.view') ? \App\Queries\Staff\Finance::read($context, $range) : null,
            'export_url' => $context->can('exports.create') && $context->can('analytics.platform.view') ? (string) route('staff.exports.store') : null,
            'filter_url' => (string) route('staff.analytics'),
            'page' => ['title' => 'Overview', 'breadcrumbs' => []],
        ]);
    }

    public function attention(): Response
    {
        return Inertia::render('attention', ['page' => ['title' => 'Attention', 'breadcrumbs' => []]]);
    }
}
