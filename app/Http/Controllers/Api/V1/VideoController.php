<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{Audio, Video};
use App\Http\Resources\VideoResource;
use App\Services\{PendingUploads, StorageService, ContentRights};
use App\Http\Requests\Videos\{StoreVideoRequest, UpdateVideoRequest};
use App\Jobs\{ProcessVideo, DeleteVideo};
use Spark\Foundation\Exceptions\ValidationException;
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
            ->orderBy('id', 'DESC')
            ->paginate(max(1, min(100, $request->integer('limit', 10))));

        return VideoResource::collection($videos);
    }

    public function show(Request $request, Video $video): JsonResource
    {
        $query = Video::visibleTo($request->user())->where('videos.status', '!=', Video::STATUS_DELETED);

        if ($request->user('id') !== $video->user_id) {
            $query->published();
        }

        return VideoResource::make($query->withApiData($request->user())->findOrFail($video->id));
    }

    public function store(StoreVideoRequest $request): Response
    {
        $input = $request->validated();
        ContentRights::apply($input);

        $video = PendingUploads::attach($request->user('id'), function () use ($request, $input): Video {
            StorageService::validateOwner($input->storage_path, $request->user('id'), 'storage_path', 'videos', true);
            StorageService::validateOwner($input->thumbnail_url, $request->user('id'), 'thumbnail_url', 'thumbnails');

            if ($input->audio_id !== null) {
                $audio = Audio::whereKey($input->audio_id)->availableTo($request->user())->first();
                if (!$audio) {
                    throw ValidationException::withMessages([
                        'audio_id' => ['Select an available sound from the audio library.'],
                    ]);
                }
            }

            $draft = $input->get('publication_intent', 'immediate') === 'draft';
            \App\Services\ReleaseMedia::attach($input->storage_path, $request->user('id'), $draft);
            if ($draft && $input->thumbnail_url) {
                throw ValidationException::withMessages(['thumbnail_url' => ['Draft covers are generated privately during encoding.']]);
            }

            $input->set('status', Video::STATUS_PROCESSING);
            $input->set('processing_state', 'processing');
            $input->set('publication_state', 'draft');

            return $request->user()->videos()->create($input);
        });

        try {
            ProcessVideo::dispatch($video->id);
        } catch (\Throwable $exception) {
            $video->update(['status' => Video::STATUS_FAILED, 'processing_state' => 'failed', 'release_error' => 'queue_unavailable']);
            throw $exception;
        }

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

        PendingUploads::attach($request->user('id'), function () use ($request, $video): void {
            abort_if($video->refresh()->status === Video::STATUS_DELETED, 404, 'Video not found.');

            abort_unless($video->publication_intent === 'immediate' && $video->publication_state !== 'scheduled' || $video->publication_state === 'published', 409, 'Use the versioned release endpoint to edit a draft.');

            $data = $request->validated();
            StorageService::validateOwner($data->thumbnail_url, $request->user('id'), 'thumbnail_url', 'thumbnails');

            if ($data->has('pinned')) {
                $data->set('pinned_at', $data->boolean('pinned') ? now()->toDateTimeString() : null);

                unset($data['pinned']);
            }

            $data->set('version', $video->version + 1);
            if (!$video->update($data)) {
                throw new \RuntimeException('Video update failed.');
            }
            if ($data->has('reuse_content')) {
                Audio::where('source_video_id', $video->id)->update(['reuse_allowed' => $data->boolean('reuse_content')]);
            }
        });

        return VideoResource::make(Video::withApiData($request->user())->findOrFail($video->id));
    }

    public function destroy(Video $video): Response
    {
        authorize('model.delete', $video);

        \App\Services\Releases::transaction($video->id, function (Video $current): void {
            \App\Services\Releases::save($current, ['status' => Video::STATUS_DELETED, 'version' => $current->version + 1, 'publish_at' => null]);
        });

        DeleteVideo::dispatch((int) $video->id);

        return json(['data' => ['message' => 'Video deleted']]);
    }
}
