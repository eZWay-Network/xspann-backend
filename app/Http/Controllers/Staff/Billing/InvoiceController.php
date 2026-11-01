<?php

namespace App\Http\Controllers\Staff\Billing;

use App\Queries\Staff\BillingRecords;
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};

final class InvoiceController
{
    public function index(Request $request): Response
    {
        return Inertia::render('billing/invoices/index', BillingRecords::listing($request, 'invoices'));
    }

    public function show(Request $request, int $record): Response
    {
        return Inertia::render('billing/invoices/show', BillingRecords::detail($request, 'invoices', $record));
    }
}
