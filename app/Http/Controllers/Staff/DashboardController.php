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
        $context = Context::from($request);
        $range = Overview::range($request->all(), $context->preferences()['timezone']);

        return Inertia::render('overview', [
            'overview' => fn() => Overview::read($context, $range),
            'filters' => $range,
            'finance' => $context->can('analytics.finance.view') ? \App\Queries\Staff\Finance::read($context, $range) : null,
            'export_url' => $context->can('exports.create') && $context->can('analytics.platform.view') ? (string) route('staff.exports.store') : null,
            'filter_url' => (string) route('staff.dashboard'),
            'page' => ['title' => 'Overview', 'breadcrumbs' => []],
        ]);
    }

    public function attention(): Response
    {
        return Inertia::render('attention', ['page' => ['title' => 'Attention', 'breadcrumbs' => []]]);
    }
}
