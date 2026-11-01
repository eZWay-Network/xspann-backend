<?php

namespace App\Http\Controllers\Staff\Billing;

use App\Queries\Staff\BillingRecords;
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};

final class SubscriptionController
{
    public function index(Request $request): Response
    {
        return Inertia::render('billing/subscriptions/index', BillingRecords::listing($request, 'subscriptions'));
    }

    public function show(Request $request, int $record): Response
    {
        return Inertia::render('billing/subscriptions/show', BillingRecords::detail($request, 'subscriptions', $record));
    }

    public function reconcile(\App\Http\Requests\Staff\RecoveryRequest $request, int $record): Response
    {
        $id = \App\Services\Staff\Operations::enqueue(Context::from($request), 'billing.reconcile', $record, $request->validated()->toArray());
        Inertia::flash('success', 'Recovery queued. Follow its recorded outcome here.');

        return Inertia::redirect((string) route('staff.operations.show', ['operation' => $id]), 303);
    }
}
