<?php

namespace App\Http\Requests\Uploads;

use Spark\Foundation\Http\FormRequest;

class AvatarUploadRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }
}
