<?php

namespace Tests\Feature;

use App\Queries\Staff\Overview;
use App\Services\Staff\{Context, StaffException};
use Spark\Facades\DB;
use Tests\TestCase;

class StaffOverviewTest extends TestCase
{
    public function testReportingBoundsAndCountsUseUtcWithLocalStorageDates(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Dhaka');
        $this->app->mergeConfig(['app' => ['timezone' => 'Asia/Dhaka']]);

        try {
            $user = $this->makeUser('analyst');
            $context = new Context($user, (object) ['role' => 'analyst'], (object) []);

            foreach (['before' => '2025-02-01 05:59:59', 'first' => '2025-02-01 06:00:00',
                'last' => '2025-02-02 05:59:59', 'after' => '2025-02-02 06:00:00'] as $name => $createdAt) {
                $account = $this->makeUser($name);
                DB::table('users')->where('id', $account->id)->update(['created_at' => $createdAt]);
            }

            $overview = Overview::read($context, Overview::range([
                'period' => 'custom',
                'from' => '2025-02-01',
                'to' => '2025-02-01',
            ]));

            $this->assertSame(['from' => '2025-02-01T00:00:00Z', 'until' => '2025-02-02T00:00:00Z'], $overview['bounds']);
            $this->assertSame([['date' => '2025-02-01', 'value' => 2]], $overview['chart']['series']);
            $this->assertSame(2, $overview['chart']['total']);
            $this->assertSame(null, $overview['chart']['comparison']);
            $this->assertSame('Retained registrations', $overview['chart']['title']);
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function testLocalDayCountsIncludeBothRepeatedHoursAndExcludeTheNextMidnight(): void
    {
        $user = $this->makeUser('analyst');
        $context = new Context($user, (object) ['role' => 'analyst'], (object) []);
        foreach (['2024-11-03 03:59:59', '2024-11-03 04:00:00', '2024-11-03 05:30:00',
            '2024-11-03 06:30:00', '2024-11-04 04:59:59', '2024-11-04 05:00:00'] as $i => $timestamp) {
            $account = $this->makeUser('boundary' . $i);
            DB::table('users')->where('id', $account->id)->update(['created_at' => $timestamp]);
        }
        $range = fn ($day) => Overview::range(['period' => 'custom', 'from' => $day, 'to' => $day], 'America/New_York');
        $overview = Overview::read($context, $range('2024-11-03'));
        $this->assertSame(['from' => '2024-11-03T04:00:00Z', 'until' => '2024-11-04T05:00:00Z'], $overview['bounds']);
        $this->assertSame([['date' => '2024-11-03', 'value' => 4]], $overview['chart']['series']);
        $this->assertSame(['from' => '2024-03-10 05:00:00', 'until' => '2024-03-11 04:00:00'], Overview::bounds($range('2024-03-10')));
    }

    public function testRepeatedMidnightStartsAtItsFirstOccurrenceAndSkippedDatesHaveNoInstants(): void
    {
        $day = ['period' => 'custom', 'from' => '2021-10-29', 'to' => '2021-10-29'];
        $this->assertSame(['from' => '2021-10-28 21:00:00', 'until' => '2021-10-29 22:00:00'],
            Overview::bounds(Overview::range($day, 'Asia/Amman')));
        $skipped = ['period' => 'custom', 'from' => '2011-12-30', 'to' => '2011-12-30'];
        $this->assertSame(['from' => '2011-12-30 10:00:00', 'until' => '2011-12-30 10:00:00'],
            Overview::bounds(Overview::range($skipped, 'Pacific/Apia')));
    }

    public function testCustomRangesAreBoundedAcrossDaylightSavingAndRejectMalformedDates(): void
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');

        try {
            $range = Overview::range(['period' => 'custom', 'from' => '2025-01-01', 'to' => '2025-03-31']);
            $this->assertSame('UTC', $range['timezone']);

            foreach (['2025-04-01', '2025-02-30', '2025-01-01 tomorrow', "2025-01-01\0", ['2025-01-01']] as $to) {
                $this->assertThrows(StaffException::class, fn () => Overview::range([
                    'period' => 'custom',
                    'from' => '2025-01-01',
                    'to' => $to,
                ]));
            }
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function testEmptyDaysRemainOrderedAndSnapshotCountsDoNotChangeWithThePeriod(): void
    {
        $user = $this->makeUser('analyst');
        $context = new Context($user, (object) ['role' => 'analyst'], (object) []);
        $overview = Overview::read($context, Overview::range([
            'period' => 'custom',
            'from' => '2025-01-01',
            'to' => '2025-01-03',
        ]));

        $this->assertSame([
            ['date' => '2025-01-01', 'value' => 0],
            ['date' => '2025-01-02', 'value' => 0],
            ['date' => '2025-01-03', 'value' => 0],
        ], $overview['chart']['series']);
        $this->assertSame(1, $overview['metrics'][0]['value']);
        $this->assertSame(0, $overview['chart']['total']);
        $this->assertSame(0, $overview['distribution']['total']);
        $this->assertSame([0, 0], array_column($overview['distribution']['items'], 'percent'));
    }
}
