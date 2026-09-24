<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use App\Services\PaginatesApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaveController extends Controller
{
    use PaginatesApiResponses;

    public function index(Request $request): JsonResponse
    {
        $videos = $request->user()
            ->savedVideos()
            ->published()
            ->visibleTo($request->user())
            ->withViewerState($request->user())
            ->with('user')
            ->latest('saves.created_at')
            ->paginate($request->integer('limit', 10));

        return $this->paginated($videos, fn ($video) => new VideoResource($video, $request->user()), $request);
    }

    public function store(Request $request, Video $video): JsonResponse
    {
        abort_unless($video->status === Video::STATUS_PUBLISHED, 404);
        abort_unless(Video::query()->whereKey($video->id)->visibleTo($request->user())->exists(), 404);

        $created = false;

        DB::transaction(function () use ($request, $video, &$created): void {
            $save = $video->saves()->firstOrCreate(['user_id' => $request->user()->id]);
            $created = $save->wasRecentlyCreated;

            if ($created) {
                $video->increment('saves_count');
            }
        });

        return response()->json([
            'data' => [
                'saved' => true,
                'saves_count' => $video->fresh()->saves_count,
                'created' => $created,
            ],
        ], $created ? 201 : 200);
    }

    public function destroy(Request $request, Video $video): JsonResponse
    {
        DB::transaction(function () use ($request, $video): void {
            $deleted = $video->saves()->where('user_id', $request->user()->id)->delete();

            if ($deleted && $video->saves_count > 0) {
                $video->decrement('saves_count');
            }
        });

        return response()->json([
            'data' => [
                'saved' => false,
                'saves_count' => $video->fresh()->saves_count,
            ],
        ]);
    }
}
