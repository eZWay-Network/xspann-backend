<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comments\StoreCommentRequest;
use App\Http\Resources\CommentResource;
use App\Models\Comment;
use App\Models\Video;
use App\Services\PaginatesApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommentController extends Controller
{
    use PaginatesApiResponses;

    public function index(Request $request, Video $video): JsonResponse
    {
        abort_unless($video->status === Video::STATUS_PUBLISHED, 404);
        abort_unless(Video::query()->whereKey($video->id)->visibleTo($request->user())->exists(), 404);

        $comments = $video->comments()
            ->whereNull('parent_id')
            ->with('user')
            ->latest()
            ->paginate($request->integer('limit', 20));

        return $this->paginated($comments, fn ($comment) => new CommentResource($comment), $request);
    }

    public function store(StoreCommentRequest $request, Video $video): JsonResponse
    {
        abort_unless($video->status === Video::STATUS_PUBLISHED, 404);
        abort_unless(Video::query()->whereKey($video->id)->visibleTo($request->user())->exists(), 404);

        $data = $request->validated();

        if (isset($data['parent_id'])) {
            abort_unless(Comment::whereKey($data['parent_id'])->where('video_id', $video->id)->exists(), 422);
        }

        $comment = DB::transaction(function () use ($request, $video, $data): Comment {
            $comment = $video->comments()->create([
                'user_id' => $request->user()->id,
                'parent_id' => $data['parent_id'] ?? null,
                'body' => $data['body'],
            ]);

            $video->increment('comments_count');

            return $comment;
        });

        return response()->json([
            'data' => (new CommentResource($comment->load('user')))->resolve($request),
        ], 201);
    }

    public function destroy(Request $request, Comment $comment): JsonResponse
    {
        $this->authorize('delete', $comment);

        DB::transaction(function () use ($comment): void {
            $video = $comment->video()->lockForUpdate()->first();
            $comment->delete();

            if ($video && $video->comments_count > 0) {
                $video->decrement('comments_count');
            }
        });

        return response()->json(['data' => ['message' => 'Comment deleted']]);
    }
}
