<?php

namespace App\Http\Requests\Staff;

final class ProfileRequest extends StaffRequest
{
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'min:1', 'max:100']];
    }
}
