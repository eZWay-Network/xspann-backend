<?php

namespace App\Services;

use App\Jobs\ProcessVideo;
use App\Models\Video;

class VideoRetry
{
    public static function retry(?int $videoId = null): bool
    {
        $ids = Video::whereIn('status', [Video::STATUS_FAILED, Video::STATUS_PROCESSING])
            ->when($videoId !== null, fn($query) => $query->whereKey($videoId))
            ->pluck('id');

        $queued = false;

        foreach ($ids as $id) {
            // A worker may have published or deleted the video since it was selected.
            $updated = Video::whereKey($id)
                ->whereIn('status', [Video::STATUS_FAILED, Video::STATUS_PROCESSING])
                ->update(['status' => Video::STATUS_PROCESSING, 'updated_at' => now()]);

            // MySQL can report zero changed rows for an already-processing video.
            if ($updated === 0 && !Video::whereKey($id)->where('status', Video::STATUS_PROCESSING)->exists()) {
                continue;
            }

            ProcessVideo::dispatch((int) $id);
            $queued = true;
        }

        return $queued;
    }
}
