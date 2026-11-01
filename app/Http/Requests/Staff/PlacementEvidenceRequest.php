<?php

namespace App\Http\Requests\Staff;

final class PlacementEvidenceRequest extends StaffRequest
{
    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'actual_starts_at' => ['required', 'string', 'max:40'],
            'actual_ends_at' => ['required', 'string', 'max:40'], 'proof' => ['required', 'string', 'max:2000'], 'note' => ['required', 'string', 'max:1000']];
    }
}
