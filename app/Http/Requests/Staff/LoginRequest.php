<?php

namespace App\Http\Requests\Staff;

final class LoginRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
            'destination' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
