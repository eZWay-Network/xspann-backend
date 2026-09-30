<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\{VideoMetadataExtractor, StorageService};
use Spark\Facades\Lock;
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;
use Throwable;
use function sprintf;

class ProcessVideo implements JobInterface
{
    use Dispatchable;

    public int $tries = 1;

    public function __construct(public int $videoId)
    {
    }

    public function handle(): void
    {
        $key = sprintf('videos.process.%d', $this->videoId);

        Lock::withLock($key, function (): void {
            $video = Video::find($this->videoId);

            if (!$video || $video->status !== Video::STATUS_PROCESSING) {
                return;
            }

            $metadata = app(VideoMetadataExtractor::class)->extract($video);

            // Keep an owner deletion from being overwritten by a running job.
            $updated = Video::whereKey($video->id)
                ->where('status', Video::STATUS_PROCESSING)
                ->update([
                    'thumbnail_url' => $video->thumbnail_url ?: ($metadata['thumbnail_url'] ?? null),
                    'duration' => $video->duration ?: ($metadata['duration'] ?? null),
                    'status' => Video::STATUS_PUBLISHED,
                    'updated_at' => now(),
                ]);

            if ((!$updated || $video->thumbnail_url) && isset($metadata['thumbnail_path'])) {
                StorageService::disk()->delete($metadata['thumbnail_path']);
            }
        }, timeout: 1800, waitTimeout: 5);
    }

    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)
            ->where('status', Video::STATUS_PROCESSING)
            ->update(['status' => Video::STATUS_FAILED, 'updated_at' => now()]);
    }
}
