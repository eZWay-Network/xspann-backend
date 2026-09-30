<?php

namespace App\Http\Resources;

use App\Services\StorageService;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray(?Request $request = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'avatar' => StorageService::publicUrl($this->avatar),
            'bio' => $this->bio,
            'followers_count' => (int) $this->followers_count,
            'following_count' => (int) $this->following_count,
            'likes_count' => (int) $this->likes_count,
            'videos_count' => (int) $this->videos_count,
            'following' => (bool) $this->viewer_following,
            'social_identities' => $this->whenLoaded('authIdentities')
        ];
    }
}
