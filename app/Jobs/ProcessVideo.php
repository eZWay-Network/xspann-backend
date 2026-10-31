<?php

namespace App\Jobs;

use App\Models\{Audio, Video};
use App\Services\{MediaProcessor, PendingUploads, Releases};
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
            if (!$video || $video->status !== Video::STATUS_PROCESSING || !Releases::eligible($video)) {
                return;
            }

            $media = app(MediaProcessor::class)->video($video);
            $paths = array_filter([$media['storage_path'], $media['thumbnail_url'] ?? null, $media['audio_path'] ?? null]);

            try {
                PendingUploads::locked($video->user_id, function () use ($video, $media): bool {
                    return DB::transaction(function () use ($video, $media): bool {
                        \App\Models\User::increment('id', 0, where: ['id' => $video->user_id]);
                        Video::increment('version', 0, where: ['id' => $video->id]);
                        $current = Video::find($video->id);
                        if (!$current || $current->status !== Video::STATUS_PROCESSING || !Releases::eligible($current)) {
                            return false;
                        }

                        if ($current->publication_intent === 'draft') {
                            foreach (['storage_path', 'thumbnail_url', 'audio_path'] as $field) {
                                \App\Services\ReleaseMedia::validate($media[$field] ?? null, $field, $field === 'storage_path');
                            }
                        }

                        $updated = Video::whereKey($video->id)
                            ->where('status', Video::STATUS_PROCESSING)
                            ->where('moderation_status', 'approved')
                            ->update([
                                'storage_path' => $media['storage_path'],
                                'thumbnail_url' => $current->thumbnail_url ?: ($media['thumbnail_url'] ?? null),
                                'duration' => $media['duration'],
                                'processing_state' => 'ready',
                                'publication_state' => $current->publication_intent === 'immediate' ? 'published' : 'draft',
                                'status' => $current->publication_intent === 'immediate' ? Video::STATUS_PUBLISHED : 'draft',
                                'published_at' => $current->publication_intent === 'immediate' ? \App\Services\Billing\Billing::timestamp() : null,
                                'release_error' => null,
                                'version' => $current->version + 1,
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

                        \App\Services\Realtime::record($video->user_id, 'release.updated', $video->id, $current->version + 1);

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

    public function failed(Throwable $exception): void
    {
        Releases::processingState($this->videoId, [Video::STATUS_PROCESSING], Video::STATUS_FAILED, 'encoding_failed');
    }
}
