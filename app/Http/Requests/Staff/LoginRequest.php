<?php

namespace App\Http\Requests\Staff;

final class LoginRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'max:1024'],
            'google_token' => ['nullable', 'string', 'max:8192'],
            'destination' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
