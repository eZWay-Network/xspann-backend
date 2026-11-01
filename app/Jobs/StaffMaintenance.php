<?php

namespace App\Jobs;

use App\Services\Staff\{Operations, Sessions};
use Spark\Facades\DB;
use Spark\Queue\Contracts\JobInterface;

final class StaffMaintenance implements JobInterface
{
    public function handle(): void
    {
        DB::table('staff_worker_heartbeats')->upsert(['worker' => 'staff', 'started_at' => Sessions::timestamp()], ['worker'], ['started_at']);
        try {
            $ids = DB::table('staff_operations')->whereIn('status', ['queued', 'running'])->orderBy('id')->limit(20)->pluck('id');
            foreach ($ids as $id) {
                Operations::run((int) $id);
            }
            DB::table('staff_operations')->whereIn('status', ['completed', 'no_op', 'failed', 'cancelled'])
                ->where('completed_at', '<', Sessions::timestamp(-90 * 86400))->delete();
            DB::table('staff_worker_heartbeats')->where('worker', 'staff')
                ->update(['succeeded_at' => Sessions::timestamp(), 'failure_code' => null]);
        } catch (\Throwable $exception) {
            DB::table('staff_worker_heartbeats')->where('worker', 'staff')->update(['failure_code' => 'worker_failed']);
            throw $exception;
        }
    }
}
