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
