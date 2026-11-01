<?php

namespace App\Http\Requests\Staff;

final class AssignmentRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'version' => 'required|integer|min:0',
            'assignee_id' => 'nullable|integer|min:1',
            'priority' => 'required|in:low,normal,high,urgent',
            'tag' => 'nullable|in:rights,safety,spam,other',
        ];
    }
}
