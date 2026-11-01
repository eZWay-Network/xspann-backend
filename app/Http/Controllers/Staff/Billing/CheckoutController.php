<?php

namespace App\Http\Controllers\Staff\Billing;

use App\Queries\Staff\BillingRecords;
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};

final class CheckoutController
{
    public function index(Request $request): Response
    {
        return Inertia::render('billing/checkouts/index', BillingRecords::listing($request, 'checkouts'));
    }

    public function show(Request $request, int $record): Response
    {
        return Inertia::render('billing/checkouts/show', BillingRecords::detail($request, 'checkouts', $record));
    }

    public function recover(\App\Http\Requests\Staff\RecoveryRequest $request, int $record): Response
    {
        $id = \App\Services\Staff\Operations::enqueue(Context::from($request), 'billing.checkout', $record, $request->validated()->toArray());
        Inertia::flash('success', 'Recovery queued. Follow its recorded outcome here.');

        return Inertia::redirect((string) route('staff.operations.show', ['operation' => $id]), 303);
    }
}
