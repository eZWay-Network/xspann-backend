<?php

namespace App\Services;

use Spark\Foundation\Exceptions\ValidationException;
use Spark\Http\Input;
use function array_key_exists;

class ContentRights
{
    public const REMOVED_FIELDS = ['sound_name', 'sound_artist', 'sound_provider', 'sound_external_id', 'sound_preview_url'];

    public static function rejectRemoved(array $input): void
    {
        $errors = [];

        foreach ([...self::REMOVED_FIELDS, 'scheduled_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $errors[$field] = [
                    $field === 'scheduled_at'
                    ? 'Use the versioned release schedule endpoint with publish_at and timezone.'
                    : 'This field is no longer supported. Use audio_id.'
                ];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public static function apply(Input $input): void
    {
        if ($input->rights_version !== config('policies.rights_version')) {
            throw ValidationException::withMessages(['rights_version' => ['Accept the current content rights declaration.']]);
        }

        unset($input['rights_confirmed']);
        $input->set('rights_confirmed_at', now()->toDateTimeString());
    }
}
