<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\VideoStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function __construct($resource, private readonly ?User $viewer = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $storage = app(VideoStorage::class);
        $coverVideo = $this->videos()
            ->published()
            ->visibleTo($this->viewer)
            ->latest()
            ->first(['thumbnail_url', 'video_url']);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => $this->avatar,
            'bio' => $this->bio,
            'followers_count' => $this->followers()->count(),
            'following_count' => $this->following()->count(),
            'following' => $this->viewer
                ? $this->viewer->following()->whereKey($this->id)->exists()
                : false,
            'cover_url' => $storage->normalizeLocalPublicUrl($coverVideo?->thumbnail_url),
            'cover_video_url' => $storage->normalizeLocalPublicUrl($coverVideo?->video_url),
        ];
    }
}
