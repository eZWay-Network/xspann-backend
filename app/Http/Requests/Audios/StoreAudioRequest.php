<?php

namespace App\Http\Requests\Audios;

use Spark\Foundation\Http\FormRequest;

class StoreAudioRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reuse_allowed' => ['required', 'boolean'],
            'rights_confirmed' => ['required', 'accepted'],
            'rights_version' => ['required', 'string', 'max:40'],
            'title' => ['required', 'string', 'min:1', 'max:120'],
            'storage_path' => ['required', 'string', 'max:2048'],
        ];
    }
}
