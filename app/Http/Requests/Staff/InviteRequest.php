<?php

namespace App\Http\Requests\Staff;

use App\Services\Staff\Permissions;

final class InviteRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', ['in' => Permissions::roles()]],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
