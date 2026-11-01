<?php

namespace App\Http\Requests\Staff;

final class PreferencesRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'timezone' => ['required', ['in' => \DateTimeZone::listIdentifiers()]],
            'locale' => ['required', ['in' => ['en']]],
            'page_size' => ['required', 'integer', ['in' => [10, 20, 50]]],
            'attention_enabled' => ['required', fn ($field, $value, $fail) => is_bool($value) ?: $fail('Choose on or off.')],
            'version' => ['required', 'integer', 'min:0'],
        ];
    }
}
