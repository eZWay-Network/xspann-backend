<?php

namespace App\Http\Requests\Releases;

use App\Http\Requests\Artists\ArtistWriteRequest;

class EditReleaseRequest extends ArtistWriteRequest
{
    public function passedValidation(): void
    {
        parent::passedValidation();
        if ($this->has('rights_confirmed') !== $this->has('rights_version')) {
            throw \Spark\Foundation\Exceptions\ValidationException::withMessages(['rights_confirmed' => ['Supply both rights_confirmed and rights_version.']]);
        }
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'caption' => ['sometimes', 'nullable', 'string', 'max:2200'],
            'visibility' => ['sometimes', 'required', 'string', ['in' => ['public', 'followers', 'private']]],
            'reuse_content' => ['sometimes', 'required', 'boolean'],
            'rights_confirmed' => ['sometimes', 'required', 'accepted'],
            'rights_version' => ['sometimes', 'required', 'string', 'max:40'],
        ];
    }
}
