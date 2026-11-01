<?php

namespace App\Jobs;

use App\Services\Realtime;
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;

class PublishRealtime implements JobInterface
{
    use Dispatchable;

    public function handle(): void
    {
        \App\Services\Staff\Heartbeats::run('realtime', fn () => $this->maintain());
    }

    private function maintain(): void
    {
        Realtime::drain();
    }
}
