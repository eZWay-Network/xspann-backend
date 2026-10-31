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
    /** Redact historical snapshots in memory using bounded queries for the whole page. */
    public static function redact(array $notifications, User $viewer): void
    {
        $actors = $videos = $comments = $messages = $announcements = [];
        foreach ($notifications as $notification) {
            $data = $notification->data;
            if ($notification->type === 'direct_message') {
                $messages[] = $data['message_id'] ?? 0;
            }
            if ($notification->type === 'artist_announcement') {
                $announcements[] = $data['announcement_id'] ?? 0;
            }
            if (isset($data['actor']['id'])) {
                $actors[] = $data['actor']['id'];
            }
            if (isset($data['video_id'])) {
                $videos[] = $data['video_id'];
            }
            foreach (['comment_id', 'parent_id'] as $field) {
                if (isset($data[$field])) {
                    $comments[] = $data[$field];
                }
            }
        }

        $messages = $messages ? array_flip(Conversations::alertMessageIds($viewer->id, $messages)) : [];
        $announcements = $announcements ? array_flip(Announcements::inboxQuery($viewer)->whereIn('id', $announcements)->pluck('id')) : [];
        $actors = $actors ? array_flip(User::visibleTo($viewer)->whereIn('users.id', array_unique($actors))->pluck('id')) : [];
        $videos = $videos ? array_flip(Video::published()->visibleTo($viewer)->whereIn('videos.id', array_unique($videos))->pluck('id')) : [];
        $comments = $comments ? array_flip(Comment::visibleTo($viewer)->whereIn('id', array_unique($comments))->pluck('id')) : [];

        foreach ($notifications as $notification) {
            $data = $notification->data;
            $unavailable = ($notification->type === 'direct_message' && !isset($messages[$data['message_id'] ?? 0]))
                || ($notification->type === 'artist_announcement' && !isset($announcements[$data['announcement_id'] ?? 0]))
                || (isset($data['actor']['id']) && !isset($actors[$data['actor']['id']]))
                || (isset($data['video_id']) && !isset($videos[$data['video_id']]))
                || (isset($data['comment_id']) && !isset($comments[$data['comment_id']]))
                || (isset($data['parent_id']) && !isset($comments[$data['parent_id']]));

            if ($unavailable) {
                $notification->data = [
                    'actor' => null,
                    'message' => 'This activity is no longer available.',
                    'video_id' => null,
                    'comment_id' => null,
                    'parent_id' => null,
                    'excerpt' => null,
                    'reaction_type' => null,
                ];
            }
        }
    }

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
