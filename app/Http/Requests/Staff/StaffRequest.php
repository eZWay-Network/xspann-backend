<?php

namespace App\Http\Requests\Staff;

use Spark\Foundation\Exceptions\ValidationException;
use Spark\Foundation\Http\FormRequest;

abstract class StaffRequest extends FormRequest
{
    public function prepareValidationError(string $message, array $errors, array $attributes = []): \Spark\Http\Response
    {
        // Spark's default validator flashes all input before sending. Staff credentials
        // must never reach that path; the perimeter owns our redirect and field errors.
        throw ValidationException::withMessages($errors);
    }

    public function passedValidation(): void
    {
        $errors = [];

        foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
            $errors[$field] = ['This field is not writable.'];
        }

        foreach ($this->all() as $field => $value) {
            if ($field === 'reason' && is_string($value) && mb_strlen(trim($value)) < 5) {
                $errors[$field] = ['Give a reason of at least five characters.'];
            }

            if (in_array($field, ['note', 'reference'], true) && is_string($value) && trim($value) === '') {
                $errors[$field] = ['Enter a meaningful value.'];
            }

            if (is_string($value) && (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0"))) {
                $errors[$field] = ['Enter valid text.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
