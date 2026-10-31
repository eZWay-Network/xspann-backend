<?php

namespace App\Http\Resources;

use App\Services\StorageService;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

class ArtistPlaylistResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        return [
            'id' => $this->id,
            'artist_id' => $this->artist_id,
            'title' => $this->title,
            'description' => $this->description,
            'genre_id' => $this->genre_id,
            'category' => $this->category,
            'visibility' => $this->visibility,
            'videos_count' => (int) $this->videos_count,
            'cover_url' => StorageService::publicUrl($this->cover_url),
            'videos' => $this->whenLoaded('videos', fn($videos) => VideoResource::collection($videos)),
            ...($this->owner_view ? [
                'version' => $this->version,
                'read_only' => (bool) $this->read_only,
                'moderation_status' => $this->moderation_status,
                'items' => $this->whenLoaded('items', fn($items) => $items->map(fn($item) => [
                    'video_id' => (int) $item->video_id,
                    'position' => (int) $item->position,
                    'status' => $item->status,
                    'visibility' => $item->visibility,
                    'moderation_status' => $item->moderation_status,
                ])->values()),
            ] : []),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
