<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Http\Resources\VideoResource;
use App\Services\VideoActions;
use Spark\Http\{Request, Resources\JsonResource, Response};

class SaveController extends Controller
{
    public function index(Request $request): JsonResource
    {
        $videos = $request->user()
            ->savedVideos()
            ->published()
            ->visibleTo($request->user())
            ->withApiData($request->user())
            ->latest('saves.created_at')
            ->paginate(max(1, min(100, $request->integer('limit', 10))));

        return VideoResource::collection($videos);
    }

    public function store(Request $request, Video $video): Response
    {
        VideoActions::visible($video);

        $created = VideoActions::transaction($video->id, function (Video $video) use ($request): bool {
            $save = $video->saves()->firstOrCreate(['user_id' => $request->user('id')]);

            if ($created = $save->wasCreated()) {
                $video->increment('saves_count');
            }

            return $created;
        });

        return json([
            'data' => [
                'saved' => true,
                'saves_count' => $video->refresh()->saves_count,
                'created' => $created,
            ],
        ], $created ? 201 : 200);
    }

    public function destroy(Request $request, Video $video): Response
    {
        VideoActions::transaction($video->id, function (Video $video) use ($request): void {
            $deleted = $video->saves()->where('user_id', $request->user('id'))->delete();

            if ($deleted && $video->saves_count > 0) {
                $video->decrement('saves_count');
            }
        });

        return json([
            'data' => [
                'saved' => false,
                'saves_count' => $video->refresh()->saves_count,
            ],
        ]);
    }
}
