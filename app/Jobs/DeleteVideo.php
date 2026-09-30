<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\StorageService;
use Spark\Facades\Lock;
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;

class DeleteVideo implements JobInterface
{
    use Dispatchable;

    public int $tries = 3;
    public array $backoff = [30, 120];

    public function __construct(public int $videoId)
    {
    }

    public function handle(): void
    {
        Lock::withLock("videos.process.$this->videoId", function () {
            $video = Video::find($this->videoId);
            if (!$video || $video->status !== Video::STATUS_DELETED) {
                return;
            }

            foreach (array_unique(array_filter([$video->storage_path, $video->thumbnail_url, $video->sound_preview_url])) as $value) {
                $location = StorageService::location($value);
                if (!$location || !preg_match("~^(videos|thumbnails|sounds)/{$video->user_id}/[^/]+\$~D", $location['key'])) {
                    continue;
                }

                $shared = Video::whereNotKey($video->id)
                    ->where(
                        fn($query) => $query
                            ->where('storage_path', $value)
                            ->orWhere('thumbnail_url', $value)
                            ->orWhere('sound_preview_url', $value)
                    )->exists();

                if (!$shared && !storage($location['disk'])->delete($location['key'])) {
                    throw new \RuntimeException('Could not remove video media.');
                }
            }

            // Foreign keys cascade to comments, reactions and video social records.
            $video->delete();
        }, timeout: 1800, waitTimeout: 5);
    }
}
