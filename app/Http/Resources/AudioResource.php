<?php

namespace App\Http\Resources;

use App\Services\{ReleaseMedia, StorageService};
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

class AudioResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        $playable = $this->status === 'ready' && $this->moderation_status === 'approved';
        $audioUrl = null;
        if ($playable && $this->origin === 'original') {
            if ($this->source_publication_state === 'published') {
                $audioUrl = StorageService::publicUrl($this->storage_path);
            } elseif ($this->source_publication_state && $request?->user('id') === $this->user_id) {
                $audioUrl = ReleaseMedia::previewUrl($this->source_video_id, 'original_audio');
            }
        } elseif ($playable) {
            $audioUrl = StorageService::publicUrl($this->storage_path);
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'audio_url' => $audioUrl,
            'duration' => $this->duration,
            'reuse_allowed' => $this->reuse_allowed,
            'moderation_status' => $this->moderation_status,
            'origin' => $this->origin,
            'status' => $this->status,
            'source_video_id' => $this->source_video_id,
            'viewer' => [
                'saved' => (bool) $this->viewer_saved
            ],
            'videos_count' => (int) $this->videos_count,
            'creator' => $this->whenLoaded('user', UserResource::make(...)),
            'created_at' => $this->created_at,
        ];
    }
}
