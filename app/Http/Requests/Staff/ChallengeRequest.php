<?php

namespace App\Http\Requests\Staff;

final class ChallengeRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:1024'],
        ];
    }
}
