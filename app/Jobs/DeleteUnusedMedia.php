<?php

namespace App\Jobs;

use App\Models\{Audio, User, Video};
use App\Services\{PendingUploads, StorageService};
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;

class DeleteUnusedMedia implements JobInterface
{
    use Dispatchable;

    public int $tries = 3;
    public array $backoff = [30, 120];

    public function __construct(public int $userId, public array $paths)
    {
    }

    public function handle(): void
    {
        PendingUploads::locked($this->userId, function (): void {
            foreach (array_unique(array_filter($this->paths)) as $value) {
                $location = StorageService::location($value);
                if (!$location || !preg_match("~^(videos|thumbnails|sounds|avatars)/{$this->userId}/[^/]+$~D", $location['key'])) {
                    continue;
                }

                $values = StorageService::referenceValues($value);
                $referenced = Video::whereIn('storage_path', $values)
                    ->orWhereIn('thumbnail_url', $values)
                    ->exists();

                if ($referenced || Audio::whereIn('storage_path', $values)->exists() || User::whereIn('avatar', $values)->exists()) {
                    continue;
                }

                if (!storage($location['disk'])->delete($location['key'])) {
                    throw new \RuntimeException('Could not remove unused media.');
                }
            }
        });
    }
}
