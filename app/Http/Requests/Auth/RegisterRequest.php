<?php

namespace App\Http\Requests\Auth;

use Spark\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'alpha_dash:ascii', 'min:3', 'max:30', 'unique:users,username'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'name' => ['nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'url', 'max:2048'],
            'bio' => ['nullable', 'string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'unique' => 'The %s has already been taken.',
            'password.confirmed' => 'The password confirmation does not match.',
        ];
    }
}
