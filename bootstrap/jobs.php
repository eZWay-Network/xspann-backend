<?php

use Spark\Queue\Queue;

/** withQueue's list registers on default; named recurring jobs need explicit pushOnce queues. */
return static function (Queue $queue): void {
    foreach ([
        ['realtime', job(\App\Jobs\PublishRealtime::class)->repeat('1 second')],
        ['notifications', job(\App\Jobs\DeliverAnnouncements::class)->repeatEveryMinutes(1)],
        ['releases', job(\App\Jobs\PublishReleases::class)->repeatEveryMinutes(1)],
        ['billing', job(\App\Jobs\BillingMaintenance::class)->repeatEveryMinutes(1)],
        ['privacy', job(\App\Jobs\FoundationMaintenance::class)->repeatEveryMinutes(5)],
        ['default', job(\App\Jobs\PruneUploads::class)->repeatDaily()],
        ['default', job([\App\Services\VideoRetry::class, 'retry'])->repeatHourly()],
        ['default', job([\App\Services\AudioRetry::class, 'retry'])->repeatHourly()],
        ['default', job([\App\Services\ArtistStatistics::class, 'collectAll'])->repeatHourly()],
    ] as [$name, $job]) {
        $queue->pushOnce($job, $name);
    }
};
