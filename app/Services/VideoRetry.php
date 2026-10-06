<?php

namespace App\Services;

use App\Jobs\ProcessVideo;
use App\Models\Video;

class VideoRetry
{
    public static function retry(): bool
    {
        $videos = Video::whereIn('status', [Video::STATUS_FAILED, Video::STATUS_PROCESSING])->get();

        if ($videos->isEmpty()) {
            return false;
        }

        foreach ($videos as $video) {
            $video->update(['status' => Video::STATUS_PROCESSING, 'updated_at' => now()]);

            ProcessVideo::dispatch($video->id);
        }

        return true;
    }
}
