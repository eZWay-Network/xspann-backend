<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Audios\StoreAudioRequest;
use App\Jobs\ProcessAudio;
use App\Services\{PendingUploads, StorageService};
use App\Http\Resources\{AudioResource, VideoResource};
use App\Models\{Audio, Video};
use Spark\Http\{Request, Response};
use Spark\Http\Resources\JsonResource;

class AudioController extends Controller
{
    public function update(Request $request, Audio $audio): JsonResource
    {
        authorize('model.update', $audio);

        $input = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:120'],
            'reuse_allowed' => ['sometimes', 'required', 'boolean'],
            'storage_path' => ['prohibited'],
            'user_id' => ['prohibited'],
            'source_video_id' => ['prohibited'],
        ]);

        PendingUploads::attach($audio->user_id, function () use ($audio, $input): void {
            abort_if($audio->refresh()->status === 'deleted', 404, 'Audio not found.');
            if (!$audio->update($input)) {
                throw new \RuntimeException('Audio update failed.');
            }
            if ($audio->source_video_id && $input->has('reuse_allowed')) {
                Video::whereKey($audio->source_video_id)->update(['reuse_content' => $input->boolean('reuse_allowed')]);
            }
        });

        return AudioResource::make(Audio::withApiData($request->user())->findOrFail($audio->id));
    }

    public function destroy(Audio $audio): Response
    {
        authorize('model.delete', $audio);
        $audio->update(['status' => 'deleted', 'reuse_allowed' => false]);
        \App\Jobs\DeleteAudio::dispatch($audio->id);

        return json(['data' => ['message' => 'Audio deleted']], 202);
    }

    public function store(StoreAudioRequest $request): Response
    {
        \App\Services\ContentRights::apply($request->validated());

        $audio = PendingUploads::attach($request->user('id'), function () use ($request): Audio {
            StorageService::validateOwner($request->validated('storage_path'), $request->user('id'), 'storage_path', 'sounds', true);

            return Audio::create([
                ...$request->validated(),
                'user_id' => $request->user('id'),
                'title' => $request->validated('title'),
                'storage_path' => $request->validated('storage_path'),
            ]);
        });

        try {
            ProcessAudio::dispatch($audio->id);
        } catch (\Throwable $exception) {
            $audio->update(['status' => 'failed']);
            throw $exception;
        }

        return AudioResource::make(Audio::withApiData($request->user())->findOrFail($audio->id))->response(201);
    }

    public function index(Request $request): JsonResource
    {
        $request->validate(['q' => ['sometimes', 'string', 'max:100']]);
        $search = trim($request->validated('q', ''));
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';

        $audios = Audio::availableTo($request->user())
            ->when(
                $search !== '',
                fn($query) => $query->whereRaw("LOWER(audios.title) LIKE LOWER(:audio_title) ESCAPE '!'", ['audio_title' => $pattern])
            )
            ->withApiData($request->user())
            ->latest()
            ->orderBy('id', 'DESC')
            ->paginate(max(1, min(100, $request->integer('limit', 20))));

        return AudioResource::collection($audios);
    }

    public function show(Request $request, Audio $audio): JsonResource
    {
        $owner = $request->user('id') === $audio->user_id
            && $request->user('status') === 'active'
            && $request->user()->hasVerifiedEmail()
            && $request->user('onboarded_at');

        abort_if($audio->status === 'deleted', 404, 'Audio not found.');

        abort_unless(
            $owner || Audio::whereKey($audio->id)->availableTo($request->user())->exists(),
            404,
            'Audio not found.',
        );

        return AudioResource::make(Audio::withApiData($request->user())->findOrFail($audio->id));
    }

    public function videos(Request $request, Audio $audio): JsonResource
    {
        abort_unless(
            Audio::whereKey($audio->id)->availableTo($request->user())->exists(),
            404,
            'Audio not found.'
        );

        $videos = Video::where('audio_id', $audio->id)
            ->published()
            ->visibleTo($request->user())
            ->withApiData($request->user())
            ->latest()
            ->orderBy('id', 'DESC')
            ->paginate(max(1, min(100, $request->integer('limit', 20))));

        return VideoResource::collection($videos);
    }
}
