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
        Realtime::drain();
    }
}
