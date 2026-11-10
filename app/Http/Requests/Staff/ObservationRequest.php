<?php

namespace App\Http\Requests\Staff;

use function is_bool;

final class ObservationRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', 'in:web,android,ios,shared'],
            'available' => ['required', 'boolean'],
            'reference' => ['required', 'string', 'max:200'],
            'note' => ['required', 'string', 'max:1000'],
            'starts_at' => ['sometimes', 'required', 'string', 'max:40'],
            'ends_at' => ['sometimes', 'required', 'string', 'max:40']
        ];
    }
    public function passedValidation(): void
    {
        parent::passedValidation();
        if (!is_bool($this->get('available'))) {
            throw \Spark\Foundation\Exceptions\ValidationException::withMessages(['available' => ['Choose a valid boolean value.']]);
        }
    }
}
