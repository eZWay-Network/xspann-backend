<?php

namespace App\Services\Staff;

use DateTimeImmutable;
use DateTimeZone;

final class Calendar
{
    /** Use the same timezone rules for staff input, display and SQL reporting. */
    public static function clock(string $timezone): array
    {
        $zone = new DateTimeZone($timezone);
        $origin = (new DateTimeImmutable('0000-01-01T00:00:00Z'))->getTimestamp();
        // Every 400 Gregorian years contain exactly 146097 days. Deduplicate matching
        // blocks of transitions so even year 9999 uses PHP's rules without a large payload.
        $span = 146097 * 86400;
        $blocks = $index = [];
        for ($i = 0; $i < 25; $i++) {
            $start = $origin + $i * $span;
            $changes = [];
            $offset = null;
            foreach ($zone->getTransitions($start, $start + $span) ?: [] as $transition) {
                if ($transition['offset'] !== $offset && $transition['ts'] < $start + $span) {
                    $offset = $transition['offset'];
                    $changes[] = [$transition['ts'] - $start, $offset];
                }
            }
            if (!$changes) {
                $changes[] = [0, $zone->getOffset(new DateTimeImmutable('@' . $start))];
            }
            $id = array_search($changes, $blocks, true);
            if ($id === false) {
                $id = count($blocks);
                $blocks[] = $changes;
            }
            $index[] = $id;
        }

        return compact('origin', 'span', 'index', 'blocks');
    }

    /** First instant of a civil date. Midnight can be skipped or occur twice. */
    public static function startOfDay(DateTimeImmutable $date, DateTimeZone $zone): DateTimeImmutable
    {
        // Use the date label as a UTC clock reading, then try each applicable local offset.
        $wall = new DateTimeImmutable($date->format('Y-m-d'), new DateTimeZone('UTC'));
        $midnight = $wall->getTimestamp();
        $transitions = $zone->getTransitions($midnight - 172800, $midnight + 172800) ?: [];
        foreach ($transitions as $index => $transition) {
            $candidate = max($transition['ts'], $midnight - $transition['offset']);
            if ($candidate < ($transitions[$index + 1]['ts'] ?? PHP_INT_MAX)) {
                return $wall->setTimestamp($candidate);
            }
        }

        // Fixed-offset zones have no transition table.
        return (new DateTimeImmutable($date->format('Y-m-d'), $zone))->setTimezone($wall->getTimezone());
    }
}
