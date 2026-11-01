<?php

namespace App\Http\Requests\Staff;

final class ExportRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', ['in' => ['dashboard', 'finance', 'operations']]],
            'period' => ['required', ['in' => ['month', '7', '30', '90', 'custom']]],
            'from' => 'sometimes|nullable|string|max:10',
            'to' => 'sometimes|nullable|string|max:10',
            'idempotency_key' => 'required|string|min:16|max:128',
        ];
    }
}
