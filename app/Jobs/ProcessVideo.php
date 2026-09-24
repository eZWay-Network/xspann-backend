<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\VideoMetadataExtractor;
use App\Services\VideoStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessVideo implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $videoId) {}

    public function handle(): void
    {
        $video = Video::find($this->videoId);

        if (! $video || $video->status !== Video::STATUS_PROCESSING) {
            return;
        }

        try {
            $publicUrl = $video->video_url ?: app(VideoStorage::class)->publicUrl($video->storage_path);
            $metadata = app(VideoMetadataExtractor::class)->extract($video);

            $video->forceFill([
                'video_url' => $publicUrl,
                'thumbnail_url' => $video->thumbnail_url ?: ($metadata['thumbnail_url'] ?? null),
                'duration' => $video->duration ?: ($metadata['duration'] ?? null),
                'status' => Video::STATUS_PUBLISHED,
            ])->save();
        } catch (Throwable) {
            $video->forceFill(['status' => Video::STATUS_FAILED])->save();
        }
    }
}
