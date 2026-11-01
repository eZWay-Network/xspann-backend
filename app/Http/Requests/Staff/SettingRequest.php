<?php

namespace App\Http\Requests\Staff;

final class SettingRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'key' => ['required', ['in' => ['workspace_name', 'support_url']]],
            'value' => 'present|nullable|string|max:255',
            'version' => 'required|integer|min:0',
        ];
    }
}
