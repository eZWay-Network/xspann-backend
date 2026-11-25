<?php

namespace App\Http\Requests\Staff;

final class PasswordRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:1024'],
            'new_password' => ['required', 'string', 'min:12', 'max:1024'],
        ];
    }
}
