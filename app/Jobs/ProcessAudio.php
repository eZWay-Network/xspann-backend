<?php

namespace App\Jobs;

use App\Models\Audio;
use App\Services\MediaProcessor;
use Spark\Facades\Cache;
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;
use Throwable;

class ProcessAudio implements JobInterface
{
    use Dispatchable;

    public int $tries = 1;

    public function __construct(public int $audioId)
    {
    }

    public function handle(): void
    {
        Cache::lock("audios.process.$this->audioId", 3600)->block(5, function (): void {
            $audio = Audio::find($this->audioId);
            if (
                !$audio || $audio->status !== 'processing' || $audio->moderation_status !== 'approved' || !$audio->rights_confirmed_at
                || !\App\Models\User::visibleTo(null)->whereKey($audio->user_id)->exists()
            ) {
                return;
            }

            $media = app(MediaProcessor::class)->audio($audio);
            try {
                $updated = Audio::whereKey($audio->id)
                    ->where('status', 'processing')
                    ->where('moderation_status', 'approved')
                    ->whereIn('user_id', \App\Models\User::select('users.id')->visibleTo(null))
                    ->update([...$media, 'status' => 'ready', 'updated_at' => now()]);
            } catch (Throwable $exception) {
                MediaProcessor::cleanup($audio->user_id, [$media['storage_path']]);
                throw $exception;
            }

            MediaProcessor::cleanup($audio->user_id, [$updated ? $audio->storage_path : $media['storage_path']]);
        });
    }

    public function failed(Throwable $exception): void
    {
        Audio::whereKey($this->audioId)
            ->where('status', 'processing')
            ->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
