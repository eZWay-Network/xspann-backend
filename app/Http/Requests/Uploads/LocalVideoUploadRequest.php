<?php

namespace App\Http\Requests\Uploads;

use Spark\Foundation\Http\FormRequest;

class LocalVideoUploadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:video/mp4,video/quicktime,video/webm', 'max:512000'],
        ];
    }
}
