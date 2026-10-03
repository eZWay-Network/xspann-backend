<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\User;
use App\Models\Video;
use Spark\Support\Str;

/** Persist activity inside the transaction that creates the social action. */
class SocialNotifications
{
    public function liked(User $actor, Video $video): void
    {
        $this->send($actor, (int) $video->user_id, Notification::VIDEO_LIKED, $video);
    }

    public function commented(User $actor, Video $video, Comment $comment, ?Comment $parent): void
    {
        if ($parent) {
            $this->send($actor, (int) $parent->user_id, Notification::COMMENT_REPLIED, $video, $comment);
        }

        // A video owner who also wrote the parent receives only the more specific reply notice.
        if (!$parent || (int) $parent->user_id !== (int) $video->user_id) {
            $this->send($actor, (int) $video->user_id, Notification::VIDEO_COMMENTED, $video, $comment);
        }
    }

    public function followed(User $actor, User $recipient): void
    {
        $this->send($actor, (int) $recipient->id, Notification::USER_FOLLOWED);
    }

    public function reacted(User $actor, Video $video, Comment $comment, string $reaction): void
    {
        $this->send($actor, (int) $comment->user_id, Notification::COMMENT_REACTED, $video, $comment, $reaction);
    }

    public function shared(User $actor, Video $video): void
    {
        $this->send($actor, (int) $video->user_id, Notification::VIDEO_SHARED, $video);
    }

    private function send(
        User $actor,
        int $recipientId,
        string $type,
        ?Video $video = null,
        ?Comment $comment = null,
        ?string $reaction = null,
    ): void {
        if ((int) $actor->id === $recipientId || $actor->status !== 'active' || !$actor->hasVerifiedEmail()) {
            return;
        }

        $recipient = User::visibleTo($actor)->whereKey($recipientId)->first();

        if (!$recipient) {
            return;
        }

        // A parent author may have lost access to a followers-only or private video.
        if ($video && !Video::published()->visibleTo($recipient)->whereKey($video->id)->exists()) {
            return;
        }

        $activity = match ($type) {
            Notification::VIDEO_LIKED => 'liked your video.',
            Notification::VIDEO_COMMENTED => 'commented on your video.',
            Notification::COMMENT_REPLIED => 'replied to your comment.',
            Notification::COMMENT_REACTED => 'reacted to your comment.',
            Notification::USER_FOLLOWED => 'started following you.',
            Notification::VIDEO_SHARED => 'shared your video.',
        };

        Notification::create([
            'user_id' => $recipientId,
            'type' => $type,
            'data' => [
                'actor' => [
                    'id' => (int) $actor->id,
                    'username' => $actor->username,
                    'name' => $actor->name,
                    // Store the stable value; the resource resolves expiring media URLs on read.
                    'avatar' => $actor->avatar,
                ],
                'message' => $actor->name . ' ' . $activity,
                'video_id' => $video ? (int) $video->id : null,
                'comment_id' => $comment ? (int) $comment->id : null,
                'parent_id' => $comment?->parent_id,
                'excerpt' => $comment ? Str::limit($comment->body, 160) : null,
                'reaction_type' => $reaction,
            ],
        ]);
    }
}
