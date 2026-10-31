<?php

namespace App\Jobs;

use App\Services\Billing\Webhooks;
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;

class ProcessBillingWebhook implements JobInterface
{
    use Dispatchable;

    public int $tries = 3;
    public array $backoff = [30, 120];

    public function __construct(public int $inboxId)
    {
    }

    public function handle(): void
    {
        app(Webhooks::class)->process($this->inboxId);
    }
}
