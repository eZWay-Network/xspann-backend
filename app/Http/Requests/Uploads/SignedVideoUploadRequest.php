<?php

namespace App\Http\Requests\Uploads;

use Spark\Foundation\Http\FormRequest;

class SignedVideoUploadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'in:video/mp4,video/quicktime,video/webm'],
        ];
    }
}
