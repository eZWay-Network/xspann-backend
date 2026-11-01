<?php

namespace App\Jobs;

use App\Services\Staff\Operations;
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;

final class ProcessStaffOperation implements JobInterface
{
    use Dispatchable;

    public int $tries = 3;
    public array $backoff = [60, 300];

    public function __construct(public int $operationId)
    {
    }

    public function handle(): void
    {
        Operations::run($this->operationId);
    }
}
