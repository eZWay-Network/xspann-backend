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
        \App\Services\Staff\Heartbeats::run('releases', fn () => $this->maintain());
    }

    private function maintain(): void
    {
        app(Releases::class)->runDue();
    }
}
