<?php

namespace App\Http\Requests\Staff;

final class BoostRemedyRequest extends StaffRequest
{
    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'action' => ['required', 'string', 'in:credit,propose_extension,extend'],
            'starts_at' => ['sometimes', 'required', 'string', 'max:40'], 'placement_version' => ['sometimes', 'required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000']];
    }
}
