<?php

namespace App\Jobs;

use App\Services\Staff\{Exports, Heartbeats, Operations, Sessions};
use Spark\Facades\DB;
use Spark\Queue\Contracts\JobInterface;

final class StaffMaintenance implements JobInterface
{
    public function handle(): void
    {
        Heartbeats::run('staff', function (): void {
            foreach (DB::table('staff_operations')->whereIn('status', ['queued', 'running'])->orderByRaw('updated_at ASC, id ASC')->limit(20)->pluck('id') as $id) {
                // Rotate attempted rows even when another worker still owns their locks.
                DB::table('staff_operations')->where('id', $id)->whereIn('status', ['queued', 'running'])
                    ->update(['updated_at' => Sessions::timestamp()]);
                Operations::run((int) $id);
            }
            Exports::maintain();
            Sessions::maintain();
            DB::table('staff_operations')->whereIn('status', ['completed', 'no_op', 'failed', 'cancelled'])
                ->where('completed_at', '<', Sessions::timestamp(-90 * 86400))->delete();
        });
    }
}
