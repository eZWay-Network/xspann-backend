<?php

namespace App\Http\Requests\Staff;

use App\Services\Moderation;

class ModerationActionRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', ['in' => Moderation::ACTIONS]],
            'target_version' => 'required|integer|min:1',
            'reason_code' => ['required', ['in' => Moderation::REASONS]],
            'reason' => 'required|string|min:5|max:2000',
            'decision_id' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|string|max:40',
        ];
    }
}
