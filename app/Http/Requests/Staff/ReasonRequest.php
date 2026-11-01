<?php

namespace App\Http\Requests\Staff;

final class ReasonRequest extends StaffRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }
}
