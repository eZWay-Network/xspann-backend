<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ViewController extends Controller
{
    public function store(Request $request, Video $video): JsonResponse
    {
        abort_unless($video->status === Video::STATUS_PUBLISHED, 404);
        abort_unless(Video::query()->whereKey($video->id)->visibleTo($request->user())->exists(), 404);

        $user = $request->user();
        $ipHash = hash('sha256', (string) $request->ip());
        $userAgentHash = hash('sha256', (string) $request->userAgent());
        $recentThreshold = now()->subHours(6);

        $exists = $video->views()
            ->where('created_at', '>=', $recentThreshold)
            ->when(
                $user,
                fn ($query) => $query->where('user_id', $user->id),
                fn ($query) => $query->where('ip_hash', $ipHash)->where('user_agent_hash', $userAgentHash),
            )
            ->exists();

        if (! $exists) {
            DB::transaction(function () use ($video, $user, $ipHash, $userAgentHash): void {
                $video->views()->create([
                    'user_id' => $user?->id,
                    'ip_hash' => $ipHash,
                    'user_agent_hash' => $userAgentHash,
                ]);

                $video->increment('views_count');
            });
        }

        return response()->json([
            'data' => [
                'viewed' => true,
                'views_count' => $video->fresh()->views_count,
            ],
        ], $exists ? 200 : 201);
    }
}
