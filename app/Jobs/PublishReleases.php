<?php

namespace App\Jobs;

use App\Services\Releases;
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;

class PublishReleases implements JobInterface
{
    use Dispatchable;

    public function handle(): void
    {
        app(Releases::class)->runDue();
    }
}
