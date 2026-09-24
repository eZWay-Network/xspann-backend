<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Videos\StoreVideoRequest;
use App\Http\Requests\Videos\UpdateVideoRequest;
use App\Http\Resources\VideoResource;
use App\Jobs\ProcessVideo;
use App\Models\Video;
use App\Services\PaginatesApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoController extends Controller
{
    use PaginatesApiResponses;

    public function index(Request $request): JsonResponse
    {
        $videos = Video::query()
            ->published()
            ->visibleTo($request->user())
            ->withViewerState($request->user())
            ->with('user')
            ->latest()
            ->paginate($request->integer('limit', 10));

        return $this->paginated($videos, fn ($video) => new VideoResource($video, $request->user()), $request);
    }

    public function show(Request $request, Video $video): JsonResponse
    {
        abort_if($video->status === Video::STATUS_DELETED, 404);
        abort_if($video->status !== Video::STATUS_PUBLISHED && $request->user()?->id !== $video->user_id, 404);
        abort_unless(Video::query()->whereKey($video->id)->visibleTo($request->user())->exists(), 404);

        return response()->json([
            'data' => (new VideoResource($video, $request->user()))->resolve($request),
        ]);
    }

    public function store(StoreVideoRequest $request): JsonResponse
    {
        $video = $request->user()->videos()->create([
            ...$request->validated(),
            'status' => Video::STATUS_PROCESSING,
        ]);

        ProcessVideo::dispatch($video->id);

        return response()->json([
            'data' => (new VideoResource($video->fresh('user'), $request->user()))->resolve($request),
        ], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $videos = $request->user()
            ->videos()
            ->where('status', '!=', Video::STATUS_DELETED)
            ->withViewerState($request->user())
            ->with('user')
            ->orderByDesc('pinned_at')
            ->latest()
            ->paginate($request->integer('limit', 20));

        return $this->paginated($videos, fn ($video) => new VideoResource($video, $request->user()), $request);
    }

    public function update(UpdateVideoRequest $request, Video $video): JsonResponse
    {
        $this->authorize('update', $video);

        $data = $request->validated();

        if (array_key_exists('pinned', $data)) {
            $data['pinned_at'] = $data['pinned'] ? now() : null;
            unset($data['pinned']);
        }

        $video->update($data);

        return response()->json([
            'data' => (new VideoResource($video->fresh('user'), $request->user()))->resolve($request),
        ]);
    }

    public function destroy(Request $request, Video $video): JsonResponse
    {
        $this->authorize('delete', $video);

        $video->update(['status' => Video::STATUS_DELETED]);

        return response()->json(['data' => ['message' => 'Video deleted']]);
    }
}
