<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Services\VideoActions;
use Spark\Http\{Request, Response};

class ViewController extends Controller
{
    public function store(Request $request, Video $video): Response
    {
        VideoActions::visible($video);

        $created = VideoActions::transaction($video->id, function (Video $video) use ($request): bool {
            $user = $request->user();
            $ipHash = hash('sha256', (string) $request->ip());
            $userAgentHash = hash('sha256', (string) $request->useragent());

            $exists = $video->views()
                ->where('created_at', '>=', now()->subHours(6))
                ->when(
                    $user,
                    fn($query) => $query->where('user_id', $user->id),
                    fn($query) => $query->where(['ip_hash' => $ipHash, 'user_agent_hash' => $userAgentHash]),
                )
                ->exists();

            if ($exists) {
                return false;
            }

            $video->views()->create([
                'user_id' => $user?->id,
                'ip_hash' => $ipHash,
                'user_agent_hash' => $userAgentHash,
            ]);

            $video->increment('views_count');

            return true;
        });

        return json([
            'data' => [
                'viewed' => true,
                'views_count' => $video->refresh()->views_count,
            ],
        ], $created ? 201 : 200);
    }
}
