<?php

namespace App\Services\Staff;

use Spark\Facades\DB;

final class Heartbeats
{
    public const TASKS = ['staff' => 180, 'billing' => 180, 'releases' => 180, 'notifications' => 180,
        'realtime' => 60, 'privacy' => 900, 'promotions' => 180, 'uploads' => 90000];

    public static function run(string $task, callable $work): void
    {
        $started = microtime(true);
        DB::table('staff_worker_heartbeats')->upsert(['worker' => $task, 'started_at' => Sessions::timestamp()], ['worker'], ['started_at']);
        try {
            $work();
            DB::table('staff_worker_heartbeats')->where('worker', $task)->update([
                'succeeded_at' => Sessions::timestamp(), 'failure_code' => null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        } catch (\Throwable $exception) {
            DB::table('staff_worker_heartbeats')->where('worker', $task)->update([
                'failure_code' => 'worker_failed', 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
            throw $exception;
        }
    }

    public static function read(): array
    {
        $rows = DB::table('staff_worker_heartbeats')->get()->keyBy('worker');
        $items = [];
        foreach (self::TASKS as $task => $staleAfter) {
            $row = $rows[$task] ?? null;
            $status = !$row ? 'unknown' : ($row->failure_code ? 'failed'
                : (!$row->succeeded_at || $row->succeeded_at < Sessions::timestamp(-$staleAfter) ? 'stale' : 'observed'));
            $items[] = ['task' => $task, 'status' => $status, 'started_at' => Sessions::iso($row?->started_at),
                'succeeded_at' => Sessions::iso($row?->succeeded_at), 'duration_ms' => isset($row->duration_ms) ? (int) $row->duration_ms : null];
        }

        return $items;
    }
}
