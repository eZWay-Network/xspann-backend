<?php

namespace App\Jobs;

use App\Models\Audio;
use App\Services\MediaProcessor;
use Spark\Facades\Lock;
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
        Lock::withLock("audios.process.$this->audioId", function (): void {
            $audio = Audio::find($this->audioId);
            if (!$audio || $audio->status !== 'processing') {
                return;
            }

            $media = app(MediaProcessor::class)->audio($audio);
            try {
                $updated = Audio::whereKey($audio->id)
                    ->where('status', 'processing')
                    ->update([...$media, 'status' => 'ready', 'updated_at' => now()]);
            } catch (Throwable $exception) {
                MediaProcessor::cleanup($audio->user_id, [$media['storage_path']]);
                throw $exception;
            }

            MediaProcessor::cleanup($audio->user_id, [$updated ? $audio->storage_path : $media['storage_path']]);
        }, timeout: 3600, waitTimeout: 5);
    }

    public function failed(Throwable $exception): void
    {
        Audio::whereKey($this->audioId)
            ->where('status', 'processing')
            ->update(['status' => 'failed', 'updated_at' => now()]);
    }
}
