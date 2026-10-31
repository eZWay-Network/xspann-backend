<?php

namespace App\Http\Resources;

use App\Services\Billing\Billing;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

class BillingInvoiceResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        return [
            'reference' => $this->invoice_id, 'status' => $this->status,
            'amount_paid' => (int) $this->amount_paid, 'amount_due' => (int) $this->amount_due,
            'currency' => $this->currency, 'issued_at' => Billing::date($this->issued_at),
            'receipt_url' => $this->hosted_url,
        ];
    }
}
