<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Services\PaginatesApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LikeController extends Controller
{
    use PaginatesApiResponses;

    public function index(Request $request): JsonResponse
    {
        $videos = $request->user()
            ->likedVideos()
            ->published()
            ->visibleTo($request->user())
            ->withViewerState($request->user())
            ->with('user')
            ->latest('likes.created_at')
            ->paginate($request->integer('limit', 10));

        return $this->paginated($videos, fn ($video) => new VideoResource($video, $request->user()), $request);
    }

    public function store(Request $request, Video $video): JsonResponse
    {
        abort_unless($video->status === Video::STATUS_PUBLISHED, 404);
        abort_unless(Video::query()->whereKey($video->id)->visibleTo($request->user())->exists(), 404);

        $created = false;

        DB::transaction(function () use ($request, $video, &$created): void {
            $like = $video->likes()->firstOrCreate(['user_id' => $request->user()->id]);
            $created = $like->wasRecentlyCreated;

            if ($created) {
                $video->increment('likes_count');
            }
        });

        return response()->json([
            'data' => [
                'liked' => true,
                'likes_count' => $video->fresh()->likes_count,
                'created' => $created,
            ],
        ], $created ? 201 : 200);
    }

    public function destroy(Request $request, Video $video): JsonResponse
    {
        DB::transaction(function () use ($request, $video): void {
            $deleted = $video->likes()->where('user_id', $request->user()->id)->delete();

            if ($deleted && $video->likes_count > 0) {
                $video->decrement('likes_count');
            }
        });

        return response()->json([
            'data' => [
                'liked' => false,
                'likes_count' => $video->fresh()->likes_count,
            ],
        ]);
    }
}
