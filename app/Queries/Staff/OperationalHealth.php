<?php

namespace App\Queries\Staff;

use App\Services\Staff\{Heartbeats, Sessions};
use Spark\Facades\DB;

final class OperationalHealth
{
    public static function read(): array
    {
        $queues = [];
        $connection = config('queue.default');
        $driver = config("queue.connections.$connection.driver");
        $availability = $driver === 'database' ? 'available' : 'unavailable';
        if ($availability === 'available') {
            try {
                $db = DB::connection(config("queue.connections.$connection.connection"));
                $table = config("queue.connections.$connection.table", 'jobs');
                foreach (['default', 'staff', 'billing', 'releases', 'notifications', 'realtime', 'privacy', 'promotions'] as $queue) {
                    $counts = $db->table($table)->where('queue', $queue)->selectRaw('status, COUNT(*) AS total, MIN(scheduled_time) AS oldest')->groupBy('status')->get();
                    $item = ['queue' => $queue, 'pending' => 0, 'processing' => 0, 'failed' => 0, 'oldest_at' => null];
                    foreach ($counts as $row) {
                        $key = $row->status === 'reserved' ? 'processing' : $row->status;
                        if (in_array($key, ['pending', 'processing', 'failed'], true)) {
                            $item[$key] += (int) $row->total;
                        }
                        if ($row->status === 'pending') {
                            $item['oldest_at'] = \App\Http\Resources\Staff\Records::date($row->oldest);
                        }
                    }
                    $queues[] = $item;
                }
            } catch (\Throwable) {
                $availability = 'unavailable';
                $queues = [];
            }
        }
        $domains = [];
        foreach ([
            ['Failed videos', 'videos', 'processing_state', 'failed', '/operations/media'],
            ['Failed audio', 'audios', 'status', 'failed', '/operations/media?kind=audio'],
            ['Queued announcements', 'announcements', 'status', 'queued', '/operations/communications'],
            ['Staff work', 'staff_operations', 'status', 'queued', '/operations/jobs'],
        ] as [$label, $table, $column, $state, $path]) {
            $query = DB::table($table)->where($column, $state);
            if ($table === 'staff_operations') {
                $query->where('environment', config('billing.environment'));
            } elseif ($table === 'videos') {
                $query->where('status', '!=', 'deleted');
            }
            $domains[] = ['label' => $label, 'count' => (clone $query)->count(),
                'oldest_at' => \App\Http\Resources\Staff\Records::date($query->selectRaw('MIN(created_at) AS earliest')->first()?->earliest, in_array($table, ['staff_operations', 'announcements'], true) ? 'UTC' : null),
                'url' => (string) url($path)];
        }

        return ['driver' => $driver, 'queue_availability' => $availability, 'queues' => $queues,
            'workers' => Heartbeats::read(), 'domains' => $domains, 'generated_at' => Sessions::iso(Sessions::timestamp())];
    }
}
