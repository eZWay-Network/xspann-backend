<?php

namespace App\Jobs;

use App\Services\Announcements;
use Spark\Facades\{DB, Log};
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;

class DeliverAnnouncements implements JobInterface
{
    use Dispatchable;

    public function handle(): void
    {
        foreach (DB::table('announcements')->where('status', 'queued')->orderByRaw('updated_at ASC, id ASC')->limit(50)->pluck('id') as $id) {
            try {
                Announcements::deliver((int) $id);
            } catch (\Throwable) {
                DB::table('announcements')->where('id', $id)->update(['updated_at' => \App\Services\Billing\Billing::timestamp()]);
                Log::error('Announcement delivery failed for local ID {id}; pending recipients remain retryable.', ['id' => $id]);
            }
        }
    }
}
