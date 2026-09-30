<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Http\Resources\VideoResource;
use App\Services\VideoActions;
use Spark\Http\{Request, Resources\JsonResource, Response};

class LikeController extends Controller
{
    public function index(Request $request): JsonResource
    {
        $videos = $request->user()
            ->likedVideos()
            ->published()
            ->visibleTo($request->user())
            ->withApiData($request->user())
            ->latest('likes.created_at')
            ->paginate(max(1, min(100, $request->integer('limit', 10))));

        return VideoResource::collection($videos);
    }

    public function store(Request $request, Video $video): Response
    {
        VideoActions::visible($video);

        $created = VideoActions::transaction($video->id, function (Video $video) use ($request): bool {
            $like = $video->likes()->firstOrCreate(['user_id' => $request->user('id')]);

            if ($created = $like->wasCreated()) {
                $video->increment('likes_count');
            }

            return $created;
        });

        return json([
            'data' => [
                'liked' => true,
                'likes_count' => $video->refresh()->likes_count,
                'created' => $created,
            ],
        ], $created ? 201 : 200);
    }

    public function destroy(Request $request, Video $video): Response
    {
        VideoActions::transaction($video->id, function (Video $video) use ($request): void {
            $deleted = $video->likes()->where('user_id', $request->user('id'))->delete();

            if ($deleted && $video->likes_count > 0) {
                $video->decrement('likes_count');
            }
        });

        return json([
            'data' => [
                'liked' => false,
                'likes_count' => $video->refresh()->likes_count,
            ],
        ]);
    }
}
