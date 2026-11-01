<?php

namespace App\Http\Requests\Staff;

final class ChallengeRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'password' => ['nullable', 'string', 'max:1024'],
            'google_token' => ['nullable', 'string', 'max:8192'],
            'mfa_code' => ['required', 'string', ['regex' => '/^\d{6}$/D']],
        ];
    }
}
