<?php

namespace App\Queries\Staff;

use App\Services\Staff\{Calendar, Context, Sessions, StaffException};
use Spark\Database\QueryBuilder;
use Spark\Facades\DB;

final class Overview
{
    public static function range(array $input, string $timezone = 'UTC'): array
    {
        $period = $input['period'] ?? 'month';

        if (!in_array($period, ['month', '7', '30', '90', 'custom'], true)) {
            throw new StaffException('Choose a supported reporting period.', 422, 'period');
        }
        $today = new \DateTimeImmutable('today', new \DateTimeZone($timezone));
        $from = $period === 'month' ? $today->modify('first day of this month')->format('Y-m-d')
            : ($period === 'custom' ? ($input['from'] ?? '') : $today->modify('-' . ((int) $period - 1) . ' days')->format('Y-m-d'));
        $to = $period === 'custom' ? ($input['to'] ?? '') : $today->format('Y-m-d');

        $dates = [];

        foreach ([$from, $to] as $date) {
            $parsed = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
                ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'))
                : false;

            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new StaffException('Choose valid start and end dates.', 422, 'from');
            }

            $dates[] = $parsed;
        }

        if ($from > $to || $to > $today->format('Y-m-d') || $dates[0]->diff($dates[1])->days >= 90) {
            throw new StaffException('Choose up to 90 days ending today or earlier.', 422, 'from');
        }

        return ['period' => $period, 'from' => $from, 'to' => $to, 'timezone' => $timezone];
    }

    /** Convert local calendar boundaries independently, including short and long days. */
    public static function bounds(array $range): array
    {
        $zone = new \DateTimeZone($range['timezone']);
        $utc = new \DateTimeZone('UTC');
        $from = new \DateTimeImmutable($range['from'], $utc);
        $until = (new \DateTimeImmutable($range['to'], $utc))->modify('+1 day');

        return [
            'from' => Calendar::startOfDay($from, $zone)->format('Y-m-d H:i:s'),
            'until' => Calendar::startOfDay($until, $zone)->format('Y-m-d H:i:s'),
        ];
    }

    private static function eligible(string $table = 'users'): QueryBuilder
    {
        $query = DB::table($table);

        if ($table !== 'users') {
            $query->join('users', 'users.id', '=', "$table.user_id");
        }

        return $query->where('users.status', 'active')->whereNotNull('users.email_verified_at')->whereNotNull('users.onboarded_at');
    }

    private static function publicVideos(): QueryBuilder
    {
        return self::eligible('videos')->where('videos.publication_state', 'published')->where('videos.processing_state', 'ready')
            ->where('videos.moderation_status', 'approved')->where('videos.visibility', 'public');
    }

    private static function metric(string $label, int $value, string $detail, string $icon): array
    {
        return compact('label', 'value', 'detail', 'icon') + ['comparison' => null];
    }

    public static function read(Context $context, array $range): array
    {
        $role = $context->membership->role;
        $environment = config('billing.environment');
        $count = fn (string $table, string $status) => DB::table($table)->where('status', $status)->count();
        $platform = $context->can('analytics.platform.view');
        $seriesQuery = DB::table('users');
        $seriesColumn = 'users.created_at';
        $seriesTitle = 'Retained registrations';
        $definition = 'Retained accounts created in each reporting day. Deleted accounts are absent; this is not daily active users.';
        $distribution = [];
        $distributionTitle = 'Artist visibility';

        if ($platform) {
            $metrics = [
                self::metric('Eligible accounts', self::eligible()->count(), 'Active, verified and onboarded now', 'users'),
                self::metric('Artist accounts', self::eligible('artists')->count(), 'All artists with eligible owners', 'artist'),
                self::metric('Public videos', self::publicVideos()->count(), 'Published, ready and approved now', 'video'),
            ];
            $metrics[] = $context->can('reports.view')
                ? self::metric('Open reports', $count('reports', 'open'), 'Reports awaiting a decision', 'flag')
                : self::metric('Listed artists', self::eligible('artists')->where('artists.listed', true)->count(), 'Listed profiles with eligible owners', 'artist');
            $distribution = [
                ['label' => 'Listed', 'value' => self::eligible('artists')->where('artists.listed', true)->count()],
                ['label' => 'Unlisted', 'value' => self::eligible('artists')->where('artists.listed', false)->count()],
            ];
        } elseif ($role === 'moderator' || $role === 'support') {
            $metrics = [
                self::metric('Open reports', $count('reports', 'open'), 'Current report queue', 'flag'),
                $context->can('appeals.view')
                    ? self::metric('Open appeals', $count('moderation_appeals', 'open'), 'Awaiting independent review', 'shield')
                    : self::metric('Pending privacy', $count('privacy_requests', 'pending'), 'Pending privacy requests', 'shield'),
                self::metric('Eligible accounts', self::eligible()->count(), 'Active, verified and onboarded now', 'users'),
                self::metric('Public videos', self::publicVideos()->count(), 'Published, ready and approved now', 'video'),
            ];
            if ($role === 'moderator') {
                $metrics[2] = self::metric('Unassigned reports', DB::table('reports')->where('status', 'open')
                    ->whereNotIn('id', DB::table('report_workflows')->select('report_id')->whereNotNull('assignee_id'))->count(),
                    'Open reports without an assigned reviewer', 'users');
                $metrics[3] = self::metric('Reports older than 24 hours', DB::table('reports')->where('status', 'open')
                    ->where('created_at', '<', now()->subHours(24)->toDateTimeString())->count(),
                    'Open cases needing triage attention', 'clock');
            }
            $seriesQuery = DB::table('reports');
            $seriesColumn = 'reports.created_at';
            $seriesTitle = 'Retained report intake';
            $definition = 'Reports created in each reporting day, across the retained case history. No evidence is included.';
            $distributionTitle = 'Report queue';
            $distribution = [['label' => 'Open', 'value' => $count('reports', 'open')], ['label' => 'Closed', 'value' => DB::table('reports')->where('status', '!=', 'open')->count()]];
        } elseif ($role === 'finance') {
            $billing = fn (string $table) => \App\Services\Billing\Billing::table($table);
            $metrics = [
                self::metric('Current paid accounts', Finance::paidAccess()->count(), 'Unrevoked current paid periods', 'users'),
                self::metric('Retained paid invoices', $billing('invoices')->where('status', 'paid')->count(), ucfirst($environment) . ' invoices across retained history', 'activity'),
                self::metric('Failed receipts', $billing('webhooks')->where('status', 'failed')->count(), 'Provider events requiring recovery', 'flag'),
                self::metric('Adjustments to review', $billing('adjustments')->where('allocation', 'review_required')->count(), 'Current allocation queue', 'shield'),
            ];
            $seriesQuery = $billing('invoices')->where('status', 'paid');
            $seriesColumn = 'billing_invoices.issued_at';
            $seriesTitle = 'Paid invoices issued';
            $definition = 'Number of retained paid invoices issued each reporting day in the ' . $environment . ' environment. Monetary totals remain separated by currency below.';
            $distributionTitle = 'Current paid-access mix';
            $distribution = array_map(fn ($row) => ['label' => $row->plan_code, 'value' => (int) $row->accounts],
                Finance::paidAccess()->selectRaw('c.plan_code, COUNT(*) AS accounts')->groupBy('c.plan_code')->get()->all());
        } elseif ($role === 'promotion_operator') {
            $boosts = fn () => DB::table('promotion_boosts')->where('environment', $environment);
            $metrics = [];

            foreach (['pending_review' => 'Pending review', 'active' => 'Active boosts', 'interrupted' => 'Interrupted boosts'] as $status => $label) {
                $metrics[] = self::metric($label, $boosts()->where('status', $status)->count(), ucfirst($environment) . ' campaigns now', 'megaphone');
            }
            $metrics[] = self::metric('Obligations awaiting evidence', DB::table('promotion_placements')
                ->where('environment', $environment)->where('status', 'awaiting_evidence')->count(),
                'Feature and network-post obligations counted separately', 'flag');
            $seriesQuery = $boosts();
            $seriesColumn = 'promotion_boosts.created_at';
            $seriesTitle = 'Retained campaign creation';
            $definition = 'Campaigns created in each reporting day in the ' . $environment . ' environment. This does not measure delivery or impressions.';
            $distributionTitle = 'Campaign status';
            $distribution = [['label' => 'Active', 'value' => $boosts()->where('status', 'active')->count()], ['label' => 'Interrupted', 'value' => $boosts()->where('status', 'interrupted')->count()]];
        } else {
            $metrics = [
                self::metric('Failed videos', DB::table('videos')->where('processing_state', 'failed')->count(), 'Processing failures now', 'video'),
                self::metric('Failed audio', $count('audios', 'failed'), 'Processing failures now', 'artist'),
                self::metric('Due releases', DB::table('videos')->where('publication_state', 'scheduled')->where('publish_at', '<=', Sessions::timestamp())->count(), 'Scheduled time has passed', 'clock'),
                self::metric('Pending deliveries', $count('announcement_deliveries', 'pending'), 'Pending announcement recipients', 'activity'),
            ];
            $seriesQuery = DB::table('videos');
            $seriesColumn = 'videos.created_at';
            $seriesTitle = 'Retained video intake';
            $definition = 'Retained video records created each reporting day, including private metadata counts. Worker health is unknown without observations.';
            $distributionTitle = 'Video processing';
            $distribution = [['label' => 'Processing', 'value' => DB::table('videos')->where('processing_state', 'processing')->count()], ['label' => 'Failed', 'value' => DB::table('videos')->where('processing_state', 'failed')->count()]];
        }

        $series = self::series($seriesQuery, $seriesColumn, $range);
        $total = array_sum(array_column($distribution, 'value'));

        foreach ($distribution as &$item) {
            $item['percent'] = $total ? round($item['value'] / $total * 100, 1) : 0;
        }
        unset($item);

        return [
            'metrics' => $metrics,
            'chart' => ['title' => $seriesTitle, 'definition' => $definition, 'series' => $series, 'total' => array_sum(array_column($series, 'value')), 'ceiling' => max(1, ...array_column($series, 'value')), 'availability' => 'available', 'comparison' => null, 'unit' => 'records'],
            'distribution' => ['title' => $distributionTitle, 'items' => $distribution, 'total' => $total],
            'generated_at' => Sessions::iso(Sessions::timestamp()),
            'bounds' => array_map(Sessions::iso(...), self::bounds($range)),
            'data_since' => null,
            'comparison_note' => 'Current counts are snapshots. No historical snapshot comparison is available.',
        ];
    }

    private static function series(QueryBuilder $query, string $column, array $range): array
    {
        // One aggregate query. Convert each reporting-day boundary into the app's storage timezone.
        $timezone = new \DateTimeZone((str_starts_with($column, 'promotion_') || str_starts_with($column, 'billing_')) ? 'UTC' : config('app.timezone', 'UTC'));
        $zone = new \DateTimeZone($range['timezone']);
        $utc = new \DateTimeZone('UTC');
        $day = new \DateTimeImmutable($range['from'], $utc);
        $last = new \DateTimeImmutable($range['to'], $utc);
        $start = Calendar::startOfDay($day, $zone);
        $expressions = $bindings = $dates = [];
        while ($day <= $last) {
            $index = count($dates);
            $nextDay = $day->modify('+1 day');
            $end = Calendar::startOfDay($nextDay, $zone);
            $expressions[] = "COALESCE(SUM(CASE WHEN $column >= :staff_from_$index AND $column < :staff_to_$index THEN 1 ELSE 0 END), 0) AS day_$index";
            $bindings["staff_from_$index"] = $start->setTimezone($timezone)->format('Y-m-d H:i:s');
            $bindings["staff_to_$index"] = $end->setTimezone($timezone)->format('Y-m-d H:i:s');
            $dates[] = $day->format('Y-m-d');
            $day = $nextDay;
            $start = $end;
        }
        $row = $query->where($column, '>=', reset($bindings))->where($column, '<', end($bindings))
            ->selectRaw(implode(', ', $expressions), $bindings)->first();

        return array_map(fn ($date, $index) => ['date' => $date, 'value' => (int) ($row->{'day_' . $index} ?? 0)], $dates, array_keys($dates));
    }
}
