<?php

namespace App\Http\Controllers\Staff\Billing;

use App\Queries\Staff\{Finance, Overview};
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};

final class ReportController
{
    public function index(Request $request): Response
    {
        $context = Context::from($request);
        $range = Overview::range($request->all());

        return Inertia::render('billing/reports', [
            'page' => ['title' => 'Finance reports', 'breadcrumbs' => []],
            'report' => Finance::read($context, $range), 'filters' => $range,
            'filter_url' => (string) route('staff.billing.reports'),
            'export_url' => $context->can('exports.create') ? (string) route('staff.exports.store') : null,
        ]);
    }
}
