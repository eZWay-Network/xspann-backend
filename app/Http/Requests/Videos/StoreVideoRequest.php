<?php

namespace App\Http\Requests\Videos;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'storage_path' => ['required', 'string', 'max:2048'],
            'video_url' => ['nullable', 'url', 'max:2048'],
            'thumbnail_url' => ['nullable', 'url', 'max:2048'],
            'caption' => ['nullable', 'string', 'max:2200'],
            'sound_name' => ['nullable', 'string', 'max:120'],
            'sound_artist' => ['nullable', 'string', 'max:120'],
            'location_name' => ['nullable', 'string', 'max:180'],
            'visibility' => ['nullable', 'string', Rule::in(['public', 'followers', 'private'])],
            'high_quality_upload' => ['nullable', 'boolean'],
            'scheduled_at' => ['nullable', 'date'],
            'trim_start' => ['nullable', 'numeric', 'min:0'],
            'trim_end' => ['nullable', 'numeric', 'gt:trim_start'],
            'cut_points' => ['nullable', 'array', 'max:20'],
            'cut_points.*' => ['numeric', 'min:0'],
            'cover_time' => ['nullable', 'numeric', 'min:0'],
            'crop_mode' => ['nullable', 'string', Rule::in(['fit', 'fill'])],
            'text_overlay' => ['nullable', 'string', 'max:120'],
            'original_audio_muted' => ['nullable', 'boolean'],
            'filter_settings' => ['nullable', 'array'],
            'filter_settings.brightness' => ['nullable', 'numeric', 'min:0', 'max:200'],
            'filter_settings.contrast' => ['nullable', 'numeric', 'min:0', 'max:250'],
            'filter_settings.saturation' => ['nullable', 'numeric', 'min:0', 'max:250'],
            'filter_settings.warmth' => ['nullable', 'numeric', 'min:-100', 'max:100'],
            'filter_settings.preset' => ['nullable', 'string', 'max:60'],
            'effect_settings' => ['nullable', 'array'],
            'effect_settings.arFace' => ['nullable', 'string', 'max:60'],
            'effect_settings.background' => ['nullable', 'string', 'max:60'],
            'effect_settings.sticker' => ['nullable', 'string', 'max:60'],
            'effect_settings.visual' => ['nullable', 'string', 'max:60'],
            'sound_provider' => ['nullable', 'string', Rule::in(['original', 'jamendo', 'local'])],
            'sound_external_id' => ['nullable', 'string', 'max:120'],
            'sound_preview_url' => ['nullable', 'url', 'max:2048'],
        ];
    }
}
