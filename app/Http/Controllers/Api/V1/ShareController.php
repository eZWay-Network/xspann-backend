<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shares\StoreShareRequest;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ShareController extends Controller
{
    public function store(StoreShareRequest $request, Video $video): JsonResponse
    {
        abort_unless($video->status === Video::STATUS_PUBLISHED, 404);
        abort_unless(Video::query()->whereKey($video->id)->visibleTo($request->user())->exists(), 404);

        DB::transaction(function () use ($request, $video): void {
            $video->shares()->create([
                'user_id' => $request->user()?->id,
                'channel' => $request->validated('channel') ?? 'copy_link',
            ]);

            $video->increment('shares_count');
        });

        return response()->json([
            'data' => [
                'video_id' => $video->id,
                'share_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/video/'.$video->id,
                'shares_count' => $video->fresh()->shares_count,
            ],
        ], 201);
    }
}
