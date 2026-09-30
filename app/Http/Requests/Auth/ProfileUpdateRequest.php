<?php

namespace App\Http\Requests\Auth;

use Spark\Facades\Auth;
use Spark\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'username' => ['sometimes', 'string', 'alpha_dash:ascii', 'min:3', 'max:30', ['unique' => ['users', 'username', Auth::id()]]],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avatar' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
