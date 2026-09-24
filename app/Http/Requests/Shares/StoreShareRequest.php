<?php

namespace App\Http\Requests\Shares;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'channel' => ['nullable', 'string', Rule::in(['copy_link', 'native_share', 'message', 'facebook', 'whatsapp', 'telegram', 'embed', 'x', 'linkedin', 'pinterest'])],
        ];
    }
}
