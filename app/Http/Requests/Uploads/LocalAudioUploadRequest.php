<?php

namespace App\Http\Requests\Uploads;

use Spark\Foundation\Http\FormRequest;

class LocalAudioUploadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:audio/mpeg,audio/wav,audio/x-wav,audio/mp4,audio/aac,audio/x-m4a,audio/m4a,audio/ogg,audio/webm', 'max:51200'],
        ];
    }
}
