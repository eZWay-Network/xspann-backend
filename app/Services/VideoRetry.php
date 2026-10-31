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
            if (!Releases::processingState((int) $id, [Video::STATUS_FAILED, Video::STATUS_PROCESSING], Video::STATUS_PROCESSING)) {
                continue;
            }

            try {
                ProcessVideo::dispatch((int) $id)->send();
            } catch (\Throwable $exception) {
                Releases::processingState((int) $id, [Video::STATUS_PROCESSING], Video::STATUS_FAILED, 'encoding_dispatch_failed');
                throw $exception;
            }
            $queued = true;
        }

        return $queued;
    }
}
