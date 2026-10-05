<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AudioResource;
use App\Models\Audio;
use Spark\Facades\Lock;
use Spark\Http\{Request, Response};
use Spark\Http\Resources\JsonResource;

class AudioSaveController extends Controller
{
    public function index(Request $request): JsonResource
    {
        $audios = $request->user()
            ->savedAudios()
            ->availableTo($request->user())
            ->withApiData($request->user())
            ->latest('audio_saves.created_at')
            ->orderBy('audio_saves.id', 'DESC')
            ->paginate(max(1, min(100, $request->integer('limit', 20))));

        return AudioResource::collection($audios);
    }

    public function store(Request $request, Audio $audio): Response
    {
        abort_unless(Audio::whereKey($audio->id)->availableTo($request->user())->exists(), 404, 'Audio not found.');

        $created = Lock::withLock("audio-saves.{$request->user('id')}.$audio->id", function () use ($request, $audio): bool {
            return $audio->saves()->firstOrCreate(['user_id' => $request->user('id')])->wasCreated();
        }, timeout: 30, waitTimeout: 5);

        return json(['data' => ['saved' => true, 'created' => $created]], $created ? 201 : 200);
    }

    public function destroy(Request $request, Audio $audio): Response
    {
        Lock::withLock("audio-saves.{$request->user('id')}.$audio->id", function () use ($request, $audio): void {
            $audio->saves()->where('user_id', $request->user('id'))->delete();
        }, timeout: 30, waitTimeout: 5);

        return json(['data' => ['saved' => false]]);
    }
}
