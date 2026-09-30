<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Http\Resources\VideoResource;
use App\Services\StorageService;
use App\Http\Requests\Videos\{StoreVideoRequest, UpdateVideoRequest};
use App\Jobs\{ProcessVideo, DeleteVideo};
use Spark\Http\{Request, Resources\JsonResource, Response};

class VideoController extends Controller
{
    public function index(Request $request): JsonResource
    {
        $videos = Video::query()
            ->published()
            ->visibleTo($request->user())
            ->withApiData($request->user())
            ->latest()
            ->paginate(max(1, min(100, $request->integer('limit', 10))));

        return VideoResource::collection($videos);
    }

    public function show(Request $request, Video $video): JsonResource
    {
        abort_if(
            $video->status === Video::STATUS_DELETED,
            404,
            'Video not found.'
        );

        abort_if(
            $video->status !== Video::STATUS_PUBLISHED && $request->user('id') !== $video->user_id,
            404,
            'Video not found.'
        );

        abort_unless(
            Video::whereKey($video->id)->visibleTo($request->user())->exists(),
            404,
            'Video not found.'
        );

        return VideoResource::make(Video::withApiData($request->user())->findOrFail($video->id));
    }

    public function store(StoreVideoRequest $request): Response
    {
        $input = $request->validated();

        StorageService::validateOwner($input->storage_path, $request->user('id'), 'storage_path', 'videos', true);
        StorageService::validateOwner($input->thumbnail_url, $request->user('id'), 'thumbnail_url', 'thumbnails');
        StorageService::validateOwner($input->sound_preview_url, $request->user('id'), 'sound_preview_url', 'sounds');

        $input->set('status', Video::STATUS_PROCESSING);

        $video = $request->user()->videos()->create($input);

        ProcessVideo::dispatch($video->id);

        return VideoResource::make(Video::withApiData($request->user())->findOrFail($video->id))->response(201);
    }

    public function mine(Request $request): JsonResource
    {
        $videos = $request->user()
            ->videos()
            ->where('status', '!=', Video::STATUS_DELETED)
            ->withApiData($request->user())
            ->orderByRaw('pinned_at DESC, videos.created_at DESC')
            ->paginate(max(1, min(100, $request->integer('limit', 20))));

        return VideoResource::collection($videos);
    }

    public function update(UpdateVideoRequest $request, Video $video): JsonResource
    {
        authorize('model.update', $video);
        abort_if($video->status === Video::STATUS_DELETED, 404, 'Video not found.');

        $data = $request->validated();

        StorageService::validateOwner($data->thumbnail_url, $request->user('id'), 'thumbnail_url', 'thumbnails');
        StorageService::validateOwner($data->sound_preview_url, $request->user('id'), 'sound_preview_url', 'sounds');

        if ($data->has('pinned')) {
            $data->set('pinned_at', $data->boolean('pinned') ? now()->toDateTimeString() : null);

            unset($data['pinned']);
        }

        $video->update($data);

        return VideoResource::make(Video::withApiData($request->user())->findOrFail($video->id));
    }

    public function destroy(Video $video): Response
    {
        authorize('model.delete', $video);

        $video->update(['status' => Video::STATUS_DELETED]);

        DeleteVideo::dispatch((int) $video->id);

        return json(['data' => ['message' => 'Video deleted']]);
    }
}
