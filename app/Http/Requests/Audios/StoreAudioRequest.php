<?php

namespace App\Http\Requests\Audios;

use Spark\Foundation\Http\FormRequest;

class StoreAudioRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:1', 'max:120'],
            'storage_path' => ['required', 'string', 'max:2048'],
        ];
    }
}
