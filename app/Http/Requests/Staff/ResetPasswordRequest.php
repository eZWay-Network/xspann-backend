<?php

namespace App\Http\Requests\Staff;

final class ResetPasswordRequest extends StaffRequest
{
    public function rules(): array
    {
        return ['email' => 'required|email|max:255', 'token' => 'required|string|max:128',
            'password' => 'required|string|min:8|max:255|confirmed', 'password_confirmation' => 'required|string|max:255'];
    }
}
