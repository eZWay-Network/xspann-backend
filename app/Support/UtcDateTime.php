<?php

namespace App\Support;

use Spark\Carbon;
use Spark\Database\Contracts\CastsAttributes;

/** Release timestamps are stored in UTC, independently of the application's display timezone. */
class UtcDateTime implements CastsAttributes
{
    public function get($value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, 'UTC')->setTimezone('UTC');
    }

    public function set($value): ?string
    {
        return $this->get($value)?->toDateTimeString();
    }
}
