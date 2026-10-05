<?php

namespace App\Http\Resources;

use App\Services\StorageService;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

class AudioResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'audio_url' => $this->status === 'ready' ? StorageService::publicUrl($this->storage_path) : null,
            'duration' => $this->duration,
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
