<?php

namespace App\Http\Controllers\Staff;

use App\Queries\Staff\Overview;
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};

final class DashboardController
{
    public function index(Request $request): Response
    {
        $range = Overview::range($request->all());

        return Inertia::render('overview', [
            'overview' => fn() => Overview::read(Context::from($request), $range),
            'filters' => $range,
            'finance' => Context::from($request)->can('analytics.finance.view') ? \App\Queries\Staff\Finance::read(Context::from($request), $range) : null,
            'export_url' => Context::from($request)->can('exports.create') && Context::from($request)->can('analytics.platform.view') ? (string) route('staff.exports.store') : null,
            'filter_url' => (string) route('staff.dashboard'),
            'page' => ['title' => 'Overview', 'breadcrumbs' => []],
        ]);
    }

    public function attention(): Response
    {
        return Inertia::render('attention', ['page' => ['title' => 'Attention', 'breadcrumbs' => []]]);
    }
}
