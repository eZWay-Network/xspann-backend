<?php

namespace App\Http\Requests\Staff;

final class NoteRequest extends StaffRequest
{
    public function rules(): array
    {
        return [
            'body' => 'required|string|min:3|max:4000',
        ];
    }

    public function passedValidation(): void
    {
        parent::passedValidation();

        if (mb_strlen(trim($this->validated('body'))) < 3) {
            throw \Spark\Foundation\Exceptions\ValidationException::withMessages([
                'body' => ['Write a note of at least three characters.'],
            ]);
        }
    }
}
