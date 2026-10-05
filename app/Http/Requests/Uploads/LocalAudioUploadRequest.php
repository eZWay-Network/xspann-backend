<?php

namespace App\Http\Requests\Uploads;

use App\Services\MediaProcessor;
use Spark\Foundation\Http\FormRequest;

class LocalAudioUploadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:51200', ['mimes' => MediaProcessor::AUDIO_MIME_TYPES]],
        ];
    }
}
