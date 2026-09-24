<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Services\PaginatesApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    use PaginatesApiResponses;

    public function index(Request $request): JsonResponse
    {
        $videos = Video::query()
            ->published()
            ->visibleTo($request->user())
            ->withViewerState($request->user())
            ->with('user')
            ->orderByDesc('created_at')
            ->paginate($request->integer('limit', 10));

        return $this->paginated($videos, fn ($video) => new VideoResource($video, $request->user()), $request);
    }

    public function following(Request $request): JsonResponse
    {
        $followingIds = $request->user()->following()->pluck('users.id');

        $videos = Video::query()
            ->published()
            ->visibleTo($request->user())
            ->whereIn('user_id', $followingIds)
            ->withViewerState($request->user())
            ->with('user')
            ->latest()
            ->paginate($request->integer('limit', 10));

        return $this->paginated($videos, fn ($video) => new VideoResource($video, $request->user()), $request);
    }
}
