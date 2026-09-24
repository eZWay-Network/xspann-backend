<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\VideoStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoResource extends JsonResource
{
    public function __construct($resource, private readonly ?User $viewer = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('user');
        $storage = app(VideoStorage::class);

        return [
            'id' => $this->id,
            'video_url' => $this->storage_path ? $storage->publicUrl($this->storage_path) : $storage->normalizeLocalPublicUrl($this->video_url),
            'thumbnail_url' => $storage->normalizeLocalPublicUrl($this->thumbnail_url),
            'caption' => $this->caption,
            'sound_name' => $this->sound_name,
            'sound_artist' => $this->sound_artist,
            'sound_provider' => $this->sound_provider,
            'sound_external_id' => $this->sound_external_id,
            'sound_preview_url' => $storage->normalizeLocalPublicUrl($this->sound_preview_url),
            'music' => $this->sound_name ?: 'Original sound',
            'tags' => collect(preg_match_all('/#[\pL\pN_]+/u', (string) $this->caption, $matches) ? $matches[0] : [])->values(),
            'duration' => $this->duration,
            'location_name' => $this->location_name,
            'visibility' => $this->visibility,
            'high_quality_upload' => $this->high_quality_upload,
            'scheduled_at' => $this->scheduled_at?->toISOString(),
            'pinned_at' => $this->pinned_at?->toISOString(),
            'edit' => [
                'trim_start' => $this->trim_start !== null ? (float) $this->trim_start : null,
                'trim_end' => $this->trim_end !== null ? (float) $this->trim_end : null,
                'cut_points' => collect($this->cut_points ?? [])->map(fn ($point) => (float) $point)->values(),
                'cover_time' => $this->cover_time !== null ? (float) $this->cover_time : null,
                'crop_mode' => $this->crop_mode,
                'text_overlay' => $this->text_overlay,
                'original_audio_muted' => (bool) $this->original_audio_muted,
                'filter_settings' => $this->filter_settings,
                'effect_settings' => $this->effect_settings,
            ],
            'status' => $this->status,
            'user' => new UserResource($this->user),
            'stats' => [
                'views' => $this->views_count,
                'likes' => $this->likes_count,
                'comments' => $this->comments_count,
                'saves' => $this->saves_count,
                'shares' => $this->shares_count,
            ],
            'viewer' => [
                'liked' => $this->viewer ? $this->viewerValue('viewer_liked', fn () => $this->likes()->where('user_id', $this->viewer->id)->exists()) : false,
                'saved' => $this->viewer ? $this->viewerValue('viewer_saved', fn () => $this->saves()->where('user_id', $this->viewer->id)->exists()) : false,
                'following' => $this->viewer ? $this->viewerValue('viewer_following', fn () => $this->viewer->following()->whereKey($this->user_id)->exists()) : false,
            ],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    private function viewerValue(string $attribute, callable $fallback): bool
    {
        if (array_key_exists($attribute, $this->resource->getAttributes())) {
            return (bool) $this->{$attribute};
        }

        return (bool) $fallback();
    }
}
