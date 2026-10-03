<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shares\StoreShareRequest;
use App\Models\Video;
use App\Services\SocialNotifications;
use App\Services\VideoActions;
use Spark\Http\Response;

class ShareController extends Controller
{
    public function store(StoreShareRequest $request, Video $video, SocialNotifications $notifications): Response
    {
        VideoActions::visible($video);

        VideoActions::transaction($video->id, function (Video $video) use ($request, $notifications): void {
            $actor = $request->user();
            $alreadyShared = $actor && $video->shares()->where('user_id', $actor->id)->exists();

            $video->shares()->create([
                'user_id' => $request->user('id'),
                'channel' => $request->validated('channel') ?? 'copy_link',
            ]);

            $video->increment('shares_count');

            if ($actor && !$alreadyShared) {
                $notifications->shared($actor, $video);
            }
        });

        return json([
            'data' => [
                'video_id' => $video->id,
                'share_url' => rtrim(config('app.frontend_url'), '/') . '/video/' . $video->id,
                'shares_count' => $video->refresh()->shares_count,
            ],
        ], 201);
    }
}
