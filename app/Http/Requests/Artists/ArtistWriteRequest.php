<?php

namespace App\Http\Requests\Artists;

use Spark\Foundation\Exceptions\ValidationException;
use Spark\Foundation\Http\FormRequest;

abstract class ArtistWriteRequest extends FormRequest
{
    public function passedValidation(): void
    {
        $errors = [];

        foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
            $errors[$field] = ['This field is not writable.'];
        }

        foreach (['stage_name', 'title'] as $field) {
            if (is_string($this->input($field)) && trim($this->input($field)) === '') {
                $errors[$field] = ['Supply a nonblank value.'];
            }
        }

        foreach (['genre_ids', 'video_ids', 'links'] as $field) {
            if ($this->has($field) && (!is_array($this->input($field)) || !array_is_list($this->input($field)))) {
                $errors[$field] = ['Supply an ordered JSON array.'];
            }
        }

        foreach (['genre_id', 'featured_video_id'] as $field) {
            if ($this->has($field) && $this->input($field) !== null && filter_var($this->input($field), FILTER_VALIDATE_INT) === false) {
                $errors[$field] = ['Supply a positive integer or null.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
