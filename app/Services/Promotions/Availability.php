<?php

namespace App\Services\Promotions;

use App\Services\AuditTrail;
use App\Services\Billing\Billing;
use Spark\Facades\DB;
use function in_array;

class Availability
{
    public const PLATFORMS = ['web', 'android', 'ios'];

    public const TERMS = 'One 24-hour Sponsored rotation in Discover on web, Android and iOS. Each second counts once when at least one enabled platform can serve your eligible video. A partial outage can reduce reach. Time unavailable on every platform returns proportional unused credit, rounded up to the next cent. Views, clicks and reach are not guaranteed. Extensions require your consent. Credit has no expiry, top-ups, transfers or cash withdrawal.';

    public static function enabledPlatforms(): array
    {
        $disabled = Promotion::table('platforms')
            ->where('surface', config('promotions.surface'))
            ->where('enabled', false)
            ->pluck('platform');

        return array_values(array_diff(self::PLATFORMS, $disabled));
    }

    public static function setEnabled(string $platform, bool $enabled): void
    {
        abort_unless(in_array($platform, self::PLATFORMS, true), 422, 'Unknown Discover platform.');

        Promotion::transaction(function () use ($platform, $enabled): void {
            $surface = config('promotions.surface');
            $switch = Promotion::table('platforms')->where('surface', $surface)->where('platform', $platform)->first();
            if ((bool) ($switch->enabled ?? true) === $enabled) {
                return;
            }

            $now = Billing::timestamp();
            $change = ['enabled' => $enabled, 'changed_at' => $now];
            if ($switch) {
                DB::table('promotion_platforms')->where('id', $switch->id)->update($change);
            } else {
                DB::table('promotion_platforms')->insert([
                    ...$change,
                    'environment' => config('billing.environment'),
                    'surface' => $surface,
                    'platform' => $platform,
                ]);
            }
            if (!$enabled) {
                // Close every open positive lease under the same mutex as renewals.
                Promotion::table('availability')->where('surface', $surface . '.' . $platform)
                    ->where('available', true)->where('ends_at', '>', $now)->update(['ends_at' => $now]);
            }
            AuditTrail::record('promotion.platform.changed', ['surface' => $surface, 'platform' => $platform, ...$change], null, 'console');
        });
    }

    public static function configured(): bool
    {
        return config('promotions.bookings_enabled') === true && Promotion::capacity() > 0;
    }

    public static function healthy(?string $platform = null): bool
    {
        $clients = self::enabledPlatforms();
        if ($platform !== null) {
            $clients = array_intersect($clients, [$platform]);
        }
        if (!$clients || Promotion::capacity() === 0) {
            return false;
        }

        $now = time();
        $surface = config('promotions.surface');
        $evidence = self::evidence($surface, config('billing.environment'), Billing::timestamp($now), Billing::timestamp($now + 1));

        return self::eligibleWindows($surface, $evidence, $clients) !== [];
    }

    public static function offer(): array
    {
        return [
            'id' => config('promotions.offer_id'),
            'surface' => config('promotions.surface'),
            'enabled' => self::configured() && self::healthy(),
            'platforms' => self::PLATFORMS,
            'enabled_platforms' => self::enabledPlatforms(),
            'configured' => self::configured(),
            'price_cents' => (int) config('promotions.price_cents'),
            'currency' => 'usd',
            'duration_seconds' => (int) config('promotions.duration_seconds'),
            'capacity' => Promotion::capacity(),
            'rights_version' => config('promotions.rights_version'),
            'disclosure' => 'Sponsored',
            'terms' => self::TERMS,
        ];
    }

    /** Trusted operator/monitor evidence. Positive windows start now and expire quickly. */
    public static function record(array $input, ?int $actor = null): object
    {
        return Promotion::transaction(function () use ($input, $actor): object {
            $platform = $input['platform'] ?? null;
            abort_unless(in_array($platform, [...self::PLATFORMS, 'shared'], true), 422, 'Choose a platform or an outage scope.');
            $inventory = config('promotions.surface');
            $surface = $platform === 'shared' ? $inventory : $inventory . '.' . $platform;
            $hash = Promotion::hash($input);
            $old = Promotion::table('availability')->where('surface', $surface)->where('reference', $input['reference'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Evidence reference already has different content.');

                return $old;
            }
            $now = Billing::timestamp();
            if ($input['available']) {
                abort_if(isset($input['starts_at']) || isset($input['ends_at']), 422, 'Available observations cannot be backdated or extended by the caller.');
                abort_unless(in_array($platform, self::enabledPlatforms(), true), 422, 'Positive evidence requires an enabled platform.');
                $start = $now;
                $end = Billing::timestamp(time() + min(120, max(1, (int) config('promotions.availability_lease_seconds'))));
            } else {
                $start = isset($input['starts_at']) ? Promotion::at($input['starts_at']) : $now;
                $end = isset($input['ends_at']) ? Promotion::at($input['ends_at']) : Billing::timestamp(time() + 120);
                abort_unless($start <= $now && $end > $start && $end <= Billing::timestamp(time() + 120), 422, 'Incident windows must overlap the past or present.');
            }
            $id = DB::table('promotion_availability')->insert([
                'environment' => config('billing.environment'),
                'surface' => $surface,
                'available' => $input['available'],
                'starts_at' => $start,
                'ends_at' => $end,
                'reference' => $input['reference'],
                'note' => $input['note'],
                'actor_id' => $actor,
                'actor_type' => $actor ? 'staff' : 'console',
                'request_hash' => $hash,
                'created_at' => $now,
            ]);
            AuditTrail::record('promotion.surface.observed', ['observation_id' => $id, 'available' => $input['available']], $actor, $actor ? 'staff' : 'console');

            return Promotion::table('availability')->where('id', $id)->first();
        });
    }

    /** Group clipped observations by evidence key and polarity. */
    private static function evidence(string $surface, string $environment, string $start, string $end, bool $incidentsOnly = false): array
    {
        $keys = [$surface];
        foreach (self::PLATFORMS as $platform) {
            $keys[] = "$surface.$platform";
        }
        $query = DB::table('promotion_availability')
            ->where('environment', $environment)
            ->whereIn('surface', $keys)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);
        if ($incidentsOnly) {
            $query->where('available', false);
        }
        $rows = $query->select(['surface', 'available', 'starts_at', 'ends_at'])->get();

        $evidence = [];
        foreach ($rows as $row) {
            $evidence[$row->surface][(int) $row->available][] = [
                Promotion::seconds(max($start, $row->starts_at)),
                Promotion::seconds(min($end, $row->ends_at)),
            ];
        }

        return $evidence;
    }

    private static function eligibleWindows(string $surface, array $evidence, array $platforms = self::PLATFORMS): array
    {
        $windows = [];
        foreach ($platforms as $platform) {
            $client = $evidence[$surface . '.' . $platform] ?? [];
            $available = self::subtract(self::merge($client[1] ?? []), self::merge($client[0] ?? []));
            array_push($windows, ...$available);
        }

        return self::subtract(self::merge($windows), self::merge($evidence[$surface][0] ?? []));
    }

    public static function merge(array $windows): array
    {
        usort($windows, fn($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($windows as [$start, $end]) {
            if ($end <= $start) {
                continue;
            }
            $last = count($merged) - 1;
            if ($last >= 0 && $start <= $merged[$last][1]) {
                $merged[$last][1] = max($end, $merged[$last][1]);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged;
    }

    public static function subtract(array $windows, array $outages): array
    {
        foreach ($outages as [$a, $b]) {
            $next = [];
            foreach ($windows as [$start, $end]) {
                if ($b <= $start || $a >= $end) {
                    $next[] = [$start, $end];
                } else {
                    if ($start < $a) {
                        $next[] = [$start, $a];
                    }
                    if ($end > $b) {
                        $next[] = [$b, $end];
                    }
                }
            }
            $windows = $next;
        }

        return $windows;
    }

    /** Called only while the creative and billing are still eligible. No fetch/event increments it. */
    public static function accrue(object $boost, string $until): void
    {
        $start = max($boost->checked_at ?? $until, $boost->starts_at);
        $end = min($until, $boost->ends_at, Invalidations::cutoff($boost) ?? $boost->ends_at);
        if ($start >= $end) {
            return;
        }
        $evidence = self::evidence($boost->surface, $boost->environment, $start, $end);
        foreach (self::eligibleWindows($boost->surface, $evidence) as [$a, $b]) {
            DB::table('promotion_delivery')->insert(['boost_id' => $boost->id, 'starts_at' => Billing::timestamp($a), 'ends_at' => Billing::timestamp($b)]);
        }
    }

    public static function delivered(object $boost): int
    {
        $windows = [];
        foreach (DB::table('promotion_delivery')->where('boost_id', $boost->id)->get() as $row) {
            $windows[] = [Promotion::seconds($row->starts_at), Promotion::seconds($row->ends_at)];
        }
        if (!$windows) {
            return 0;
        }

        $lost = self::incidentLoss(
            $boost->surface,
            $boost->environment,
            Billing::timestamp(min(array_column($windows, 0))),
            Billing::timestamp(max(array_column($windows, 1)))
        );

        return self::deliveredSeconds($boost, $windows, $lost, Invalidations::cutoff($boost));
    }

    /** Accrued windows already passed the serving union. Only incident time needs rechecking. */
    public static function incidentLoss(string $surface, string $environment, string $start, string $end): array
    {
        $incidents = self::evidence($surface, $environment, $start, $end, incidentsOnly: true);
        $shared = $incidents[$surface][0] ?? [];
        $scoped = [];
        foreach (self::PLATFORMS as $platform) {
            array_push($scoped, ...($incidents[$surface . '.' . $platform][0] ?? []));
        }
        $scoped = self::merge($scoped);
        if (!$scoped) {
            return self::merge($shared);
        }

        $evidence = self::evidence(
            $surface,
            $environment,
            Billing::timestamp($scoped[0][0]),
            Billing::timestamp(end($scoped)[1])
        );
        $uncovered = self::subtract($scoped, self::eligibleWindows($surface, $evidence));

        return self::merge([...$shared, ...$uncovered]);
    }

    public static function deliveredSeconds(object $boost, array $windows, array $lost, ?string $cutoff): int
    {
        if ($cutoff) {
            $lost[] = [Promotion::seconds($cutoff), PHP_INT_MAX];
        }
        $seconds = 0;
        foreach (self::subtract(self::merge($windows), $lost) as [$start, $end]) {
            $seconds += $end - $start;
        }

        return min((int) $boost->duration_seconds, $seconds);
    }
}
