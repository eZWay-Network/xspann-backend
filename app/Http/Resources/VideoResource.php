<?php

namespace App\Http\Resources;

use App\Services\StorageService;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

class VideoResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        return [
            'id' => $this->id,
            'audio_id' => $this->audio_id,
            'audio_mode' => $this->audio_mode,
            'audio_settings' => $this->audio_settings,
            'audio' => $this->whenLoaded('audio', AudioResource::make(...)),
            'video_url' => StorageService::publicUrl($this->storage_path),
            'thumbnail_url' => StorageService::publicUrl($this->thumbnail_url),
            'caption' => $this->caption,
            'sound_name' => $this->sound_name,
            'sound_artist' => $this->sound_artist,
            'sound_provider' => $this->sound_provider,
            'sound_external_id' => $this->sound_external_id,
            'sound_preview_url' => StorageService::publicUrl($this->sound_preview_url),
            'music' => $this->sound_name ?: 'Original sound',
            'tags' => collect(preg_match_all('/#[\pL\pN_]+/u', (string) $this->caption, $matches) ? $matches[0] : [])->values(),
            'duration' => $this->duration,
            'location_name' => $this->location_name,
            'visibility' => $this->visibility,
            'high_quality_upload' => $this->high_quality_upload,
            'scheduled_at' => $this->scheduled_at,
            'pinned_at' => $this->pinned_at,
            'edit' => [
                'trim_start' => $this->trim_start !== null ? (float) $this->trim_start : null,
                'trim_end' => $this->trim_end !== null ? (float) $this->trim_end : null,
                'cut_points' => collect($this->cut_points ?? [])->map(fn($point) => (float) $point)->values(),
                'cover_time' => $this->cover_time !== null ? (float) $this->cover_time : null,
                'crop_mode' => $this->crop_mode,
                'text_overlay' => $this->text_overlay,
                'original_audio_muted' => (bool) $this->original_audio_muted,
                'filter_settings' => $this->filter_settings,
                'effect_settings' => $this->effect_settings,
            ],
            'status' => $this->status,
            'user' => $this->whenLoaded('user', UserResource::make(...)),
            'stats' => [
                'views' => $this->views_count,
                'likes' => $this->likes_count,
                'comments' => $this->comments_count,
                'saves' => $this->saves_count,
                'shares' => $this->shares_count,
            ],
            'viewer' => [
                'liked' => (bool) $this->viewer_liked,
                'saved' => (bool) $this->viewer_saved,
                'following' => (bool) $this->viewer_following,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
