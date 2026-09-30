<?php

namespace App\Http\Requests\Shares;

use Spark\Foundation\Http\FormRequest;

class StoreShareRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'channel' => ['nullable', 'string', ['in' => ['copy_link', 'native_share', 'message', 'facebook', 'whatsapp', 'telegram', 'embed', 'x', 'linkedin', 'pinterest']]],
        ];
    }
}
