<?php

namespace App\Http\Requests\Staff;

final class PlacementRequest extends StaffRequest
{
    public function rules(): array
    {
        return ['artist_id' => ['required', 'integer', 'min:1'], 'month' => ['required', 'string', 'max:7'],
            'type' => ['required', 'string', 'in:feature,network_post'], 'network_account' => ['required', 'string', 'max:80'],
            'capacity_confirmed' => ['required', 'boolean'], 'starts_at' => ['sometimes', 'required', 'string', 'max:40'],
            'ends_at' => ['sometimes', 'required', 'string', 'max:40'], 'reason' => ['required', 'string', 'max:1000']];
    }
    public function passedValidation(): void
    {
        parent::passedValidation();
        if (!is_bool($this->get('capacity_confirmed'))) {
            throw \Spark\Foundation\Exceptions\ValidationException::withMessages(['capacity_confirmed' => ['Choose a valid boolean value.']]);
        }
    }
}
