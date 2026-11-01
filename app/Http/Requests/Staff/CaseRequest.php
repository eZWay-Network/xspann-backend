<?php

namespace App\Http\Requests\Staff;

final class CaseRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'target_type' => 'required|in:user,video,audio,comment,playlist,boost',
            'target_id' => 'required|integer|min:1',
            'reason' => 'required|string|min:5|max:255',
            'details' => 'nullable|string|max:2000',
        ];
    }
}
