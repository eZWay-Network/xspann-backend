<?php

namespace App\Http\Requests\Staff;

final class AllocationRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'allocation' => ['required', ['in' => ['full_period', 'no_service_change', 'released']]],
            'reason' => 'required|string|min:10|max:1000',
            'version' => 'required|string|size:64',
        ];
    }
}
