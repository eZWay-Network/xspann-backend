<?php

namespace Tests\Unit;

use Spark\Http\Resources\JsonResource;
use Spark\Testing\TestCase;

class ApiDateTest extends TestCase
{
    public function testDatesAreSerializedAsUtcWithoutMutatingTheSource(): void
    {
        $date = new \Spark\Carbon('2026-09-26 18:00:00+06:00');
        $this->assertSame('2026-09-26T12:00:00.000000Z', JsonResource::normalize($date));
        $this->assertSame('+06:00', $date->format('P'));
        $this->assertSame(null, JsonResource::normalize(null));
    }
}
