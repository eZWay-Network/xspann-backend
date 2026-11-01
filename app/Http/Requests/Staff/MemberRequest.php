<?php

namespace App\Http\Requests\Staff;

use App\Services\Staff\Permissions;

final class MemberRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', ['in' => ['role', 'disable', 'enable', 'revoke_sessions']]],
            'role' => ['required', ['in' => Permissions::roles()]],
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }
}
