<?php

namespace App\Services;

use App\Jobs\ProcessVideo;
use App\Models\Video;
use function in_array;

class VideoRetry
{
    public static function retry(int $id): bool
    {
        $video = Video::findOrFail($id);

        if (!in_array($video->status, [Video::STATUS_FAILED, Video::STATUS_PROCESSING], true)) {
            return false;
        }

        Video::whereKey($id)
            ->whereIn('status', [Video::STATUS_FAILED, Video::STATUS_PROCESSING])
            ->update(['status' => Video::STATUS_PROCESSING, 'updated_at' => now()]);

        ProcessVideo::dispatch($id);

        return true;
    }
}
