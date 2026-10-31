<?php

namespace App\Services\Promotions;

use App\Services\AuditTrail;
use App\Services\Billing\Billing;
use Spark\Facades\DB;

class Availability
{
    public static function configured(): bool
    {
        $url = config('promotions.surface_url');

        return config('promotions.bookings_enabled') === true && is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https'
            && !parse_url($url, PHP_URL_USER) && !parse_url($url, PHP_URL_FRAGMENT);
    }

    public static function healthy(): bool
    {
        $now = Billing::timestamp();
        $query = fn() => Promotion::table('availability')->where('surface', config('promotions.surface'))
            ->where('starts_at', '<=', $now)->where('ends_at', '>', $now);

        return self::configured() && $query()->where('available', true)->exists() && !$query()->where('available', false)->exists();
    }

    public static function offer(): array
    {
        return [
            'id' => config('promotions.offer_id'), 'surface' => config('promotions.surface'),
            'enabled' => self::healthy(), 'configured' => self::configured(),
            'price_cents' => (int) config('promotions.price_cents'), 'currency' => 'usd',
            'duration_seconds' => (int) config('promotions.duration_seconds'), 'capacity' => (int) config('promotions.capacity'),
            'rights_version' => config('promotions.rights_version'),
            'disclosure' => 'Sponsored',
            'terms' => 'A 24-hour rotation slot on the web Promoted videos surface. No guaranteed views, followers or sales. No top-ups, transfers, withdrawal or credit expiry. Only independently recorded eligible availability counts. Unused time returns ceil(price_cents × undelivered_seconds / duration_seconds) credit cents; an extension requires explicit owner consent.',
        ];
    }

    /** Trusted operator/monitor evidence. Positive windows start now and expire quickly. */
    public static function record(array $input, ?int $actor = null): object
    {
        return Promotion::transaction(function () use ($input, $actor): object {
            $hash = Promotion::hash($input);
            $old = Promotion::table('availability')->where('surface', config('promotions.surface'))->where('reference', $input['reference'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Evidence reference already has different content.');

                return $old;
            }
            $now = Billing::timestamp();
            if ($input['available']) {
                abort_if(isset($input['starts_at']) || isset($input['ends_at']), 422, 'Available observations cannot be backdated or extended by the caller.');
                abort_unless(self::configured(), 503, 'Verify and configure the actual web surface before recording availability.');
                $start = $now;
                $end = Billing::timestamp(time() + min(120, max(1, (int) config('promotions.availability_lease_seconds'))));
            } else {
                $start = isset($input['starts_at']) ? Promotion::at($input['starts_at']) : $now;
                $end = isset($input['ends_at']) ? Promotion::at($input['ends_at']) : Billing::timestamp(time() + 120);
                abort_unless($start <= $now && $end > $start && $end <= Billing::timestamp(time() + 120), 422, 'Incident windows must overlap the past or present.');
            }
            $id = DB::table('promotion_availability')->insert([
                'environment' => config('billing.environment'), 'surface' => config('promotions.surface'),
                'available' => $input['available'], 'starts_at' => $start, 'ends_at' => $end,
                'reference' => $input['reference'], 'note' => $input['note'], 'actor_id' => $actor,
                'actor_type' => $actor ? 'staff' : 'console', 'request_hash' => $hash, 'created_at' => $now,
            ]);
            AuditTrail::record('promotion.surface.observed', ['observation_id' => $id, 'available' => $input['available']], $actor, $actor ? 'staff' : 'console');

            return Promotion::table('availability')->where('id', $id)->first();
        });
    }

    public static function windows(string $surface, string $start, string $end, bool $available): array
    {
        $windows = [];
        foreach (Promotion::table('availability')->where('surface', $surface)->where('available', $available)
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->get() as $row) {
            $windows[] = [max(Promotion::seconds($start), Promotion::seconds($row->starts_at)), min(Promotion::seconds($end), Promotion::seconds($row->ends_at))];
        }

        return self::merge($windows);
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
        foreach (self::subtract(self::windows($boost->surface, $start, $end, true), self::windows($boost->surface, $start, $end, false)) as [$a, $b]) {
            DB::table('promotion_delivery')->insert(['boost_id' => $boost->id, 'starts_at' => Billing::timestamp($a), 'ends_at' => Billing::timestamp($b)]);
        }
    }

    public static function delivered(object $boost): int
    {
        $windows = [];
        foreach (DB::table('promotion_delivery')->where('boost_id', $boost->id)->get() as $row) {
            $windows[] = [Promotion::seconds($row->starts_at), Promotion::seconds($row->ends_at)];
        }
        if ($cutoff = Invalidations::cutoff($boost)) {
            $windows = self::subtract($windows, [[Promotion::seconds($cutoff), PHP_INT_MAX]]);
        }
        $windows = self::merge($windows);
        if (!$windows) {
            return 0;
        }
        // Late incident evidence can lower delivery and trigger an additional capped reversal.
        $outages = self::windows($boost->surface, Billing::timestamp($windows[0][0]), Billing::timestamp(end($windows)[1]), false);
        $seconds = 0;
        foreach (self::subtract($windows, $outages) as [$a, $b]) {
            $seconds += $b - $a;
        }

        return min((int) $boost->duration_seconds, $seconds);
    }
}
