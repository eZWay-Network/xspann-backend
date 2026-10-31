<?php

namespace App\Http\Requests\Videos;

use Spark\Foundation\Http\FormRequest;
use Spark\Foundation\Exceptions\ValidationException;
use function array_key_exists;

class UpdateVideoRequest extends FormRequest
{
    private const CREATION_FIELDS = [
        'publication_intent',
        'processing_state',
        'publication_state',
        'publish_at',
        'published_at',
        'version',
        'audio_id',
        'audio_mode',
        'audio_settings',
        'trim_start',
        'trim_end',
        'cut_points',
        'cover_time',
        'crop_mode',
        'text_overlay',
        'original_audio_muted',
        'filter_settings',
        'effect_settings',
    ];

    public function rules(): array
    {
        return [
            ...array_fill_keys(self::CREATION_FIELDS, ['prohibited']),
            'reuse_content' => ['sometimes', 'required', 'boolean'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'thumbnail_url' => ['nullable', 'url', 'max:2048'],
            'location_name' => ['nullable', 'string', 'max:180'],
            'visibility' => ['sometimes', 'required', 'string', ['in' => ['public', 'followers', 'private']]],
            'high_quality_upload' => ['sometimes', 'required', 'boolean'],
            'scheduled_at' => ['prohibited'],
            'pinned' => ['sometimes', 'required', 'boolean'],
            'sound_name' => ['prohibited'],
            'sound_artist' => ['prohibited'],
            'sound_provider' => ['prohibited'],
            'sound_external_id' => ['prohibited'],
            'sound_preview_url' => ['prohibited'],
        ];
    }

    public function passedValidation(): void
    {
        \App\Services\ContentRights::rejectRemoved($this->all());

        // Spark's prohibited rule permits null/empty values; field presence is forbidden here.
        $input = $this->all();
        $errors = [];
        foreach (self::CREATION_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $errors[$field] = ['Media settings cannot be changed after creation. Upload a new video for a different edit.'];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }
}
