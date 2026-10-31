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
                ->update(['status' => Video::STATUS_PROCESSING, 'processing_state' => 'processing', 'release_error' => null, 'updated_at' => now()]);

            // MySQL can report zero changed rows for an already-processing video.
            if ($updated === 0 && !Video::whereKey($id)->where('status', Video::STATUS_PROCESSING)->exists()) {
                continue;
            }

            try {
                ProcessVideo::dispatch((int) $id)->send();
            } catch (\Throwable $exception) {
                Video::whereKey($id)->where('status', Video::STATUS_PROCESSING)->update([
                    'status' => Video::STATUS_FAILED, 'processing_state' => 'failed',
                    'release_error' => 'encoding_dispatch_failed', 'updated_at' => now(),
                ]);
                throw $exception;
            }
            $queued = true;
        }

        return $queued;
    }
}
