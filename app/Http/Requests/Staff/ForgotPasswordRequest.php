<?php

namespace App\Http\Requests\Staff;

final class ForgotPasswordRequest extends StaffRequest
{
    public function rules(): array
    {
        return ['email' => 'required|email|max:255'];
    }
}
