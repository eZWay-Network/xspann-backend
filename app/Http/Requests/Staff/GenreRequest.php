<?php

namespace App\Http\Requests\Staff;

final class GenreRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'name' => 'required|string|min:2|max:80',
            'slug' => 'required|string|min:2|max:80|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
            'position' => 'required|integer|min:0|max:10000',
            'archived' => ['required', fn ($field, $value, $fail) => is_bool($value) ?: $fail('Choose active or archived.')],
            'version' => 'required|integer|min:0',
        ];
    }
}
