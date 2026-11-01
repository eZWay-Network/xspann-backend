<?php

namespace App\Http\Requests\Staff;

final class AppealDecisionRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'version' => 'required|integer|min:1',
            'outcome' => 'required|in:uphold,overturn',
            'reason' => 'required|string|min:5|max:2000',
        ];
    }
}
