<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function __construct($resource, private readonly ?User $viewer = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'avatar' => $this->avatar,
            'bio' => $this->bio,
            'followers_count' => $this->followers()->count(),
            'following_count' => $this->following()->count(),
            'likes_count' => (int) $this->videos()->published()->visibleTo($this->viewer)->sum('likes_count'),
            'videos_count' => $this->videos()->published()->visibleTo($this->viewer)->count(),
            'following' => $this->viewer
                ? $this->viewer->following()->whereKey($this->id)->exists()
                : false,
        ];
    }
}
