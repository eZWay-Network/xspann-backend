<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Video;

class VideoPolicy
{
    public function update(User $user, Video $video): bool
    {
        return $video->user_id === $user->id;
    }

    public function delete(User $user, Video $video): bool
    {
        return $video->user_id === $user->id;
    }
}
