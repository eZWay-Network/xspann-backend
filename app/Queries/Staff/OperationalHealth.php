<?php

namespace App\Queries\Staff;

use App\Services\Staff\{Heartbeats, Sessions};
use Spark\Facades\DB;
use Spark\Queue\Queue;

final class OperationalHealth
{
    public static function read(): array
    {
        $queues = [];
        $connection = config('queue.default');
        $driver = config("queue.connections.$connection.driver", $connection);
        try {
            $queues = self::queues($connection, $driver);
            $availability = 'available';
        } catch (\Throwable) {
            $availability = 'unavailable';
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

    private static function queues(string $connection, string $driver): array
    {
        $empty = ['pending' => 0, 'processing' => 0, 'failed' => 0, 'oldest_at' => null];
        $queues = array_fill_keys(['default', 'staff', 'billing', 'releases', 'notifications', 'realtime', 'privacy', 'promotions'], $empty);

        foreach (self::counts($connection, $driver) as [$name, $status, $count, $scheduled]) {
            $queues[$name] ??= $empty;
            $queues[$name][$status === 'reserved' ? 'processing' : $status] += $count;
            if ($status === 'pending' && ($queues[$name]['oldest_at'] === null || $scheduled < $queues[$name]['oldest_at'])) {
                $queues[$name]['oldest_at'] = $scheduled;
            }
        }

        $rows = [];
        foreach ($queues as $name => $counts) {
            $rows[] = ['queue' => $name, ...$counts];
        }

        return $rows;
    }

    private static function counts(string $connection, string $driver): iterable
    {
        $statuses = ['pending', 'reserved', 'processing', 'failed'];
        if ($driver === 'database') {
            // One snapshot, even while workers remove jobs. The database does not need to return job payloads.
            $config = config("queue.connections.$connection");
            $database = !empty($config['connection']) ? DB::connection($config['connection']) : app(\Spark\Database\DB::class);
            $rows = $database->table($config['table'] ?? 'jobs')->whereIn('status', $statuses)
                ->selectRaw('queue, status, COUNT(*) AS total, MIN(scheduled_time) AS oldest')->groupBy(['queue', 'status'])->get();

            foreach ($rows as $row) {
                yield [$row->queue, $row->status, (int) $row->total, Sessions::iso($row->oldest)];
            }
            return;
        }

        // The default facade initializes recurring jobs. A dedicated reader only inspects the configured transport.
        $reader = new Queue();
        foreach ($reader->getJobs(status: $statuses, to: PHP_INT_MAX) as $job) {
            yield [$job->getQueueName(), $job->getMetadata('status'), 1, gmdate('Y-m-d\TH:i:s\Z', $job->getScheduledTime()->getTimestamp())];
        }
    }
}
