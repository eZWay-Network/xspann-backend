<?php

namespace App\Http\Requests\Staff;

final class BoostDecisionRequest extends StaffRequest
{
    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'decision' => ['required', 'string', 'in:approve,reject,restrict,hold,release_hold'],
            'reason' => ['required', 'string', 'max:1000']];
    }
}
