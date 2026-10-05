<?php

namespace App\Jobs;

use App\Models\{Audio, Video};
use App\Services\{MediaProcessor, PendingUploads};
use Spark\Facades\{DB, Lock};
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;
use Throwable;

class ProcessVideo implements JobInterface
{
    use Dispatchable;

    public int $tries = 1;

    public function __construct(public int $videoId)
    {
    }

    public function handle(): void
    {
        Lock::withLock("videos.process.$this->videoId", function (): void {
            $video = Video::find($this->videoId);
            if (!$video || $video->status !== Video::STATUS_PROCESSING) {
                return;
            }

            $media = app(MediaProcessor::class)->video($video);
            $paths = array_filter([$media['storage_path'], $media['thumbnail_url'] ?? null, $media['audio_path'] ?? null]);

            try {
                PendingUploads::locked($video->user_id, function () use ($video, $media): bool {
                    return DB::transaction(function () use ($video, $media): bool {
                        $current = Video::find($video->id);
                        if (!$current || $current->status !== Video::STATUS_PROCESSING) {
                            return false;
                        }

                        $updated = Video::whereKey($video->id)
                            ->where('status', Video::STATUS_PROCESSING)
                            ->update([
                                'storage_path' => $media['storage_path'],
                                'thumbnail_url' => $current->thumbnail_url ?: ($media['thumbnail_url'] ?? null),
                                'duration' => $media['duration'],
                                'status' => Video::STATUS_PUBLISHED,
                                'updated_at' => now(),
                            ]);

                        if (!$updated) {
                            return false;
                        }

                        if (isset($media['audio_path'])) {
                            $audio = Audio::create([
                                'user_id' => $video->user_id,
                                'source_video_id' => $video->id,
                                'title' => mb_substr('Original sound - ' . $video->user->name, 0, 120),
                                'origin' => 'original',
                                'status' => 'ready',
                                'storage_path' => $media['audio_path'],
                                'duration' => $media['duration'],
                            ]);

                            Video::whereKey($video->id)->update(['audio_id' => $audio->id]);
                        }

                        return true;
                    });
                });
            } catch (Throwable $exception) {
                MediaProcessor::cleanup($video->user_id, $paths);
                throw $exception;
            }

            MediaProcessor::cleanup($video->user_id, [$video->storage_path, ...$paths]);
        }, timeout: 3600, waitTimeout: 5);
    }

    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)
            ->where('status', Video::STATUS_PROCESSING)
            ->update(['status' => Video::STATUS_FAILED, 'updated_at' => now()]);
    }
}
