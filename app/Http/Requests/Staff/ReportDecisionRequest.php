<?php

namespace App\Http\Requests\Staff;

final class ReportDecisionRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'version' => 'required|integer|min:1',
            'action' => 'required|in:dismiss,restrict,restore',
            'reason' => 'required|string|min:5|max:2000',
        ];
    }
}
