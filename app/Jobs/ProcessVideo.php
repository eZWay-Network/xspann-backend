<?php

namespace App\Jobs;

use App\Models\{Audio, Video};
use App\Services\{MediaProcessor, PendingUploads};
use Spark\Facades\{DB, Cache};
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
        Cache::lock("videos.process.$this->videoId", 3600)->block(5, function (): void {
            $video = Video::find($this->videoId);
            if (!$video || $video->status !== Video::STATUS_PROCESSING || !self::eligible($video)) {
                return;
            }

            $media = app(MediaProcessor::class)->video($video);
            $paths = array_filter([$media['storage_path'], $media['thumbnail_url'] ?? null, $media['audio_path'] ?? null]);

            try {
                PendingUploads::locked($video->user_id, function () use ($video, $media): bool {
                    return DB::transaction(function () use ($video, $media): bool {
                        $current = Video::find($video->id);
                        if (!$current || $current->status !== Video::STATUS_PROCESSING || !self::eligible($current)) {
                            return false;
                        }

                        $updated = Video::whereKey($video->id)
                            ->where('status', Video::STATUS_PROCESSING)
                            ->where('moderation_status', 'approved')
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

                        if (
                            $current->reuse_content && !$current->audio_id
                            && isset($media['audio_path'])
                        ) {
                            $audio = Audio::create([
                                'user_id' => $video->user_id,
                                'source_video_id' => $video->id,
                                'title' => mb_substr('Original sound - ' . $video->user->name, 0, 120),
                                'origin' => 'original',
                                'reuse_allowed' => true,
                                'rights_version' => $current->rights_version,
                                'rights_confirmed_at' => $current->rights_confirmed_at?->toDateTimeString(),
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
        });
    }

    private static function eligible(Video $video): bool
    {
        $user = $video->user()->first();
        if (
            !$user || $user->status !== 'active' || !$user->hasVerifiedEmail() || !$user->onboarded_at
            || !$video->rights_confirmed_at || $video->moderation_status !== 'approved'
        ) {
            return false;
        }

        return !$video->audio_id || Audio::whereKey($video->audio_id)->availableTo($user)->exists();
    }

    public function failed(Throwable $exception): void
    {
        Video::whereKey($this->videoId)
            ->where('status', Video::STATUS_PROCESSING)
            ->update(['status' => Video::STATUS_FAILED, 'updated_at' => now()]);
    }
}
