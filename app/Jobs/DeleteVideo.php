<?php

namespace App\Jobs;

use App\Models\{Audio, Video};
use App\Services\{PendingUploads, StorageService};
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

            PendingUploads::locked($video->user_id, function () use ($video): void {
                $video = $video->refresh();

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

                    $shared = $shared || Audio::where('storage_path', $value)->exists();

                    if (!$shared && !storage($location['disk'])->delete($location['key'])) {
                        throw new \RuntimeException('Could not remove video media.');
                    }
                }

                foreach (Audio::where('source_video_id', $video->id)->get() as $audio) {
                    $location = StorageService::location($audio->storage_path);
                    $shared = Audio::whereNotKey($audio->id)->where('storage_path', $audio->storage_path)->exists()
                        || Video::whereNotKey($video->id)
                            ->where(fn($query) => $query
                                ->where('storage_path', $audio->storage_path)
                                ->orWhere('thumbnail_url', $audio->storage_path)
                                ->orWhere('sound_preview_url', $audio->storage_path))
                            ->exists();

                    if ($location && !$shared && !storage($location['disk'])->delete($location['key'])) {
                        throw new \RuntimeException('Could not remove extracted audio.');
                    }
                    $audio->delete();
                }

                // Foreign keys cascade to comments, reactions and video social records.
                $video->delete();
            });
        }, timeout: 3600, waitTimeout: 5);
    }
}
