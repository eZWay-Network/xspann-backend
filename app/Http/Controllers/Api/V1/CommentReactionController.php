<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommentResource;
use App\Models\{Comment, CommentReaction};
use App\Services\VideoActions;
use Spark\Http\{Request, Resources\JsonResource, Response};

class CommentReactionController extends Controller
{
    public function store(Request $request, Comment $comment): JsonResource
    {
        $request->validate([
            'reaction_type' => ['sometimes', 'string', ['in' => CommentReaction::TYPES]]
        ]);

        $this->visible($request, $comment);

        CommentReaction::query()
            ->upsert([
                'comment_id' => $comment->id,
                'user_id' => $request->user('id'),
                'reaction_type' => $request->validated('reaction_type', 'like'),
                'created_at' => now(),
            ], ['user_id', 'comment_id'], ['reaction_type']);

        return CommentResource::make(Comment::withApiData($request->user())->findOrFail($comment->id));
    }

    public function destroy(Request $request, Comment $comment): JsonResource
    {
        $this->visible($request, $comment);

        CommentReaction::query()
            ->where('comment_id', $comment->id)
            ->where('user_id', $request->user('id'))
            ->delete();

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
