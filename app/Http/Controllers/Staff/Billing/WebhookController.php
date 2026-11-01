<?php

namespace App\Http\Controllers\Staff\Billing;

use App\Queries\Staff\BillingRecords;
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};

final class WebhookController
{
    public function index(Request $request): Response
    {
        return Inertia::render('billing/webhooks/index', BillingRecords::listing($request, 'webhooks'));
    }

    public function show(Request $request, int $record): Response
    {
        return Inertia::render('billing/webhooks/show', BillingRecords::detail($request, 'webhooks', $record));
    }

    public function retry(\App\Http\Requests\Staff\RecoveryRequest $request, int $record): Response
    {
        $id = \App\Services\Staff\Operations::enqueue(Context::from($request), 'billing.webhook', $record, $request->validated()->toArray());
        Inertia::flash('success', 'Recovery queued. Follow its recorded outcome here.');

        return Inertia::redirect((string) route('staff.operations.show', ['operation' => $id]), 303);
    }
}
