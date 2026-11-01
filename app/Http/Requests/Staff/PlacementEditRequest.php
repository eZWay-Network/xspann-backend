<?php

namespace App\Http\Requests\Staff;

final class PlacementEditRequest extends StaffRequest
{
    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'action' => ['required', 'string', 'in:schedule,interrupt,cancel'],
            'starts_at' => ['sometimes', 'required', 'string', 'max:40'], 'ends_at' => ['sometimes', 'required', 'string', 'max:40'],
            'reason' => ['required', 'string', 'max:1000']];
    }
}
