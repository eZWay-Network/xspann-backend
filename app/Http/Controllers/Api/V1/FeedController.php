<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Http\Resources\VideoResource;
use Spark\Http\{Request, Resources\JsonResource};

class FeedController extends Controller
{
    public function index(Request $request): JsonResource
    {
        $videos = Video::published()
            ->visibleTo($request->user())
            ->withApiData($request->user())
            ->latest()
            ->paginate(max(1, min(100, $request->integer('limit', 10))));

        return VideoResource::collection($videos);
    }

    public function following(Request $request): JsonResource
    {
        $videos = Video::published()
            ->visibleTo($request->user())
            ->whereIn('videos.user_id', $request->user()->following()->select('users.id'))
            ->withApiData($request->user())
            ->latest()
            ->paginate(max(1, min(100, $request->integer('limit', 10))));

        return VideoResource::collection($videos);
    }
}
