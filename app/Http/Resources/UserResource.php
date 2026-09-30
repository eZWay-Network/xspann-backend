<?php

namespace App\Http\Resources;

use App\Services\StorageService;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'avatar' => StorageService::publicUrl($this->avatar),
            'bio' => $this->bio,
            'followers_count' => (int) $this->followers_count,
            'following_count' => (int) $this->following_count,
            'following' => (bool) $this->viewer_following,
            'cover_url' => StorageService::publicUrl($this->cover_url),
            'cover_video_url' => StorageService::publicUrl($this->cover_video_url),
        ];
    }
}
