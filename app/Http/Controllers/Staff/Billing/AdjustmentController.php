<?php

namespace App\Http\Controllers\Staff\Billing;

use App\Http\Requests\Staff\AllocationRequest;
use App\Queries\Staff\BillingRecords;
use App\Services\Billing\Adjustments;
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};

final class AdjustmentController
{
    public function index(Request $request): Response
    {
        return Inertia::render('billing/adjustments/index', BillingRecords::listing($request, 'adjustments'));
    }

    public function show(Request $request, int $record): Response
    {
        return Inertia::render('billing/adjustments/show', BillingRecords::detail($request, 'adjustments', $record));
    }

    public function allocate(AllocationRequest $request, int $record): Response
    {
        $context = Context::from($request);
        $input = $request->validated();
        Adjustments::allocate($record, $input['allocation'], trim($input['reason']), $context, $input['version']);
        Inertia::flash('success', 'Adjustment allocation recorded.');

        return Inertia::redirect((string) route('staff.billing.adjustments.show', ['record' => $record]), 303);
    }
}
