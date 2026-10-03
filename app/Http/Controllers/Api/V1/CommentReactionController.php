<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Models\{Comment, CommentReaction, Video};
use App\Services\SocialNotifications;
use App\Services\VideoActions;
use Spark\Http\{Request, Resources\JsonResource};

class CommentReactionController extends Controller
{
    public function store(Request $request, Comment $comment, SocialNotifications $notifications): JsonResource
    {
        $request->validate([
            'reaction_type' => ['sometimes', 'string', ['in' => CommentReaction::TYPES]]
        ]);

        $this->visible($request, $comment);

        VideoActions::transaction((int) $comment->video_id, function (Video $video) use ($request, $comment, $notifications): void {
            $attributes = ['comment_id' => $comment->id, 'user_id' => $request->user('id')];
            $exists = CommentReaction::where($attributes)->exists();
            $reaction = $request->validated('reaction_type', 'like');

            CommentReaction::upsert([
                ...$attributes,
                'reaction_type' => $reaction,
                'created_at' => now(),
            ], conflict: ['user_id', 'comment_id'], update: ['reaction_type']);

            if (!$exists) {
                $notifications->reacted($request->user(), $video, $comment, $reaction);
            }
        });

        return CommentResource::make(Comment::withApiData($request->user())->findOrFail($comment->id));
    }

    public function destroy(Request $request, Comment $comment): JsonResource
    {
        $this->visible($request, $comment);

        VideoActions::transaction((int) $comment->video_id, function () use ($request, $comment): void {
            CommentReaction::where('comment_id', $comment->id)
                ->where('user_id', $request->user('id'))
                ->delete();
        });

        return CommentResource::make(Comment::withApiData($request->user())->findOrFail($comment->id));
    }

    private function visible(Request $request, Comment $comment): void
    {
        VideoActions::visible((int) $comment->video_id);

        abort_unless(
            Comment::visibleTo($request->user())->whereKey($comment->id)->exists(),
            404,
            'Comment not found.'
        );
    }
}
