<?php

namespace App\Jobs;

use App\Services\{ChunkUploads, PendingUploads};
use Spark\Facades\Lock;
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;

class PruneUploads implements JobInterface
{
    use Dispatchable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function handle(): void
    {
        Lock::withLock('uploads.prune', function (): void {
            $cutoff = time() - max(24, (int) config('storage.pending_upload_hours', 48)) * 3600;
            PendingUploads::prune($cutoff);
            app(ChunkUploads::class)->prune($cutoff);
        }, timeout: 3600, waitTimeout: 1);
    }
}
