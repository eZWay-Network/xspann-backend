<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comments\StoreCommentRequest;
use App\Models\{Comment, Video};
use App\Http\Resources\CommentResource;
use App\Services\SocialNotifications;
use App\Services\VideoActions;
use Spark\Facades\Auth;
use Spark\Http\{Request, Resources\JsonResource, Response};

class CommentController extends Controller
{
    public function index(Video $video, Request $request): JsonResource
    {
        $video = VideoActions::visible($video);

        $comments = $video->comments()
            ->whereNull('parent_id')
            ->visibleTo(Auth::user())
            ->withApiData(Auth::user())
            ->latest()
            ->paginate(max(1, min(100, $request->integer('limit', 20))));

        return CommentResource::collection($comments);
    }

    public function store(Video $video, StoreCommentRequest $request, SocialNotifications $notifications): Response
    {
        $data = $request->validated();

        $video = VideoActions::visible($video);

        $comment = VideoActions::transaction($video->id, function (Video $video) use ($data, $notifications) {
            $parent = isset($data['parent_id'])
                ? Comment::visibleTo(Auth::user())
                    ->whereKey($data['parent_id'])
                    ->where('video_id', $video->id)
                    ->first()
                : null;

            abort_if(isset($data['parent_id']) && !$parent, 422, 'The selected parent_id is invalid.');

            $comment = $video->comments()->create([...$data, 'user_id' => Auth::id()]);
            $video->increment('comments_count');
            $notifications->commented(Auth::user(), $video, $comment, $parent);

            return $comment->refresh();
        });

        return CommentResource::make(Comment::withApiData(Auth::user())->findOrFail($comment->id))->response(201);
    }

    public function replies(Comment $comment): JsonResource
    {
        VideoActions::visible((int) $comment->video_id);

        abort_unless(Comment::visibleTo(Auth::user())->whereKey($comment->id)->exists(), 404, 'Comment not found.');

        $replies = $comment->replies()
            ->visibleTo(Auth::user())
            ->withApiData(Auth::user())
            ->latest()
            ->paginate(max(1, min(100, request()->integer('limit', 20))));

        return CommentResource::collection($replies);
    }

    public function destroy(Comment $comment): Response
    {
        authorize('model.delete', $comment);

        VideoActions::transaction((int) $comment->video_id, function () use ($comment) {
            $video = $comment->video()->lockForUpdate()->first();
            $comment->delete();

            if ($video) {
                $video->update(['comments_count' => $video->comments()->count()]);
            }
        });

        return json(['data' => ['message' => 'Comment deleted']]);
    }
}
