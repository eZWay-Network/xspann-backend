<?php

namespace App\Http\Requests\Staff;

final class PrivacyRetryRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'reason' => 'required|string|min:5|max:1000',
            'idempotency_key' => 'required|string|min:16|max:128',
        ];
    }
}
