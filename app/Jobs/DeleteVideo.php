<?php

namespace App\Jobs;

use App\Models\{Audio, Video};
use App\Services\{PendingUploads, StorageService};
use Spark\Facades\Cache;
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
        Cache::lock("videos.process.$this->videoId", 3600)->block(5, function () {
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

                    $values = StorageService::referenceValues($value);
                    $shared = Video::whereNotKey($video->id)
                        ->where(
                            fn($query) => $query
                                ->whereIn('storage_path', $values)
                                ->orWhereIn('thumbnail_url', $values)
                                ->orWhereIn('sound_preview_url', $values)
                        )->exists();

                    $shared = $shared || Audio::whereIn('storage_path', $values)->exists();

                    if (!$shared && !storage($location['disk'])->delete($location['key'])) {
                        throw new \RuntimeException('Could not remove video media.');
                    }
                }

                foreach (Audio::where('source_video_id', $video->id)->get() as $audio) {
                    $location = StorageService::location($audio->storage_path);
                    $values = StorageService::referenceValues($audio->storage_path);
                    $shared = Audio::whereNotKey($audio->id)->whereIn('storage_path', $values)->exists()
                        || Video::whereNotKey($video->id)
                            ->where(fn($query) => $query
                                ->whereIn('storage_path', $values)
                                ->orWhereIn('thumbnail_url', $values)
                                ->orWhereIn('sound_preview_url', $values))
                            ->exists();

                    if ($location && !$shared && !storage($location['disk'])->delete($location['key'])) {
                        throw new \RuntimeException('Could not remove extracted audio.');
                    }
                    $audio->delete();
                }

                // Foreign keys cascade to comments, reactions and video social records.
                $video->delete();
            });
        });
    }
}
