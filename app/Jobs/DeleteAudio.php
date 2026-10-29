<?php

namespace App\Jobs;

use App\Models\{Audio, User, Video};
use App\Services\{PendingUploads, StorageService};
use Spark\Facades\Cache;
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;

class DeleteAudio implements JobInterface
{
    use Dispatchable;

    public int $tries = 3;
    public array $backoff = [30, 120];

    public function __construct(public int $audioId)
    {
    }

    public function handle(): void
    {
        Cache::lock("audios.process.$this->audioId", 3600)->block(5, function (): void {
            $audio = Audio::find($this->audioId);
            if (!$audio || $audio->status !== 'deleted') {
                return;
            }
            PendingUploads::locked($audio->user_id, function () use ($audio): void {
                $location = StorageService::location($audio->storage_path);
                $values = StorageService::referenceValues($audio->storage_path);
                $shared = Audio::whereNotKey($audio->id)->whereIn('storage_path', $values)->exists()
                    || Video::whereIn('storage_path', $values)->orWhereIn('thumbnail_url', $values)->exists()
                    || User::whereIn('avatar', $values)->exists();

                if (
                    $location && preg_match("~^sounds/{$audio->user_id}/[^/]+$~D", $location['key']) && !$shared
                    && !storage($location['disk'])->delete($location['key'])
                ) {
                    throw new \RuntimeException('Could not remove audio media.');
                }
                if (!$audio->remove()) {
                    throw new \RuntimeException('Could not remove audio record.');
                }
            });
        });
    }
}
