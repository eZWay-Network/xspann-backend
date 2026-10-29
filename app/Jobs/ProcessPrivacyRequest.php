<?php

namespace App\Jobs;

use App\Services\PrivacyRequests;
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;

class ProcessPrivacyRequest implements JobInterface
{
    use Dispatchable;

    public int $tries = 3;
    public array $backoff = [60, 300];

    public function __construct(public int $requestId)
    {
    }

    public function handle(): void
    {
        PrivacyRequests::process($this->requestId);
    }
}
