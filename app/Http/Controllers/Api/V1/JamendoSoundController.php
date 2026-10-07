<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Services\JamendoSoundService;
use Spark\Http\{Request, Response};
use Spark\Http\Resources\JsonResource;

class JamendoSoundController extends Controller
{
    public function index(Request $request, JamendoSoundService $sounds): Response
    {
        $input = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ]);

        return json($sounds->search(trim($input->get('q', '')), (int) $input->get('limit', 20), (int) $input->get('page', 1)));
    }

    public function show(string $track, JamendoSoundService $sounds): Response
    {
        $sound = $sounds->find($track);
        abort_unless($sound !== null, 404, 'Sound not found.');

        return json(['data' => $sound]);
    }

    public function videos(Request $request, string $track): JsonResource
    {
        abort_unless(preg_match('/^[1-9][0-9]{0,19}$/D', $track) === 1, 404, 'Sound not found.');

        $videos = Video::where('sound_provider', 'jamendo')
            ->where('sound_external_id', $track)
            ->published()
            ->visibleTo($request->user())
            ->withApiData($request->user())
            ->latest()
            ->orderBy('id', 'DESC')
            ->paginate(max(1, min(100, $request->integer('limit', 20))));

        return VideoResource::collection($videos);
    }
}
