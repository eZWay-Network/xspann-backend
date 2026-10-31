<?php

namespace App\Services\Promotions;

use App\Models\{Artist, User, Video};
use App\Services\{Artists, Releases};
use App\Services\Billing\Billing;
use Spark\Database\QueryBuilder;
use Spark\Facades\DB;

class Promotion
{
    public static function table(string $name): QueryBuilder
    {
        return DB::table('promotion_' . $name)->where('environment', config('billing.environment'));
    }

    /** A database mutex, not an expiring cache lock, protects credit and interval inventory. */
    public static function transaction(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback): mixed {
            if (DB::table('promotion_locks')->where('id', 1)->update(['revision' => DB::raw('revision + 1')]) !== 1) {
                throw new \RuntimeException('Promotion database mutex is missing.');
            }

            return $callback();
        });
    }

    public static function at(string $value): string
    {
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $value), 422, 'Use an ISO-8601 date with an explicit offset.');
        try {
            $date = new \DateTimeImmutable($value);
            $errors = \DateTimeImmutable::getLastErrors();
        } catch (\Exception) {
            abort(422, 'Invalid date.');
        }
        abort_if($errors && ($errors['warning_count'] || $errors['error_count']), 422, 'Invalid date.');
        abort_unless(abs($date->getOffset()) <= 50400 && (int) $date->format('Y') >= 2020 && (int) $date->format('Y') <= 2100, 422, 'Date is outside the supported range.');

        return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function seconds(string $value): int
    {
        return (int) strtotime($value . ' UTC');
    }

    public static function future(string $value): void
    {
        abort_unless(self::seconds($value) > time() && self::seconds($value) <= time() + (int) config('promotions.booking_horizon_days') * 86400, 422, 'Start must be in the future and within the booking horizon.');
    }

    public static function owner(User $user): Artist
    {
        $artist = Artists::owned($user);
        abort_unless(self::artistEligible($artist->id), 403, 'Artist is not eligible for promotion.');

        return $artist;
    }

    public static function artistEligible(?int $id, ?User $viewer = null): bool
    {
        return $id && Artist::visibleTo($viewer)->where('listed', true)->whereKey($id)->exists();
    }

    public static function creative(?int $artistId, ?int $videoId, ?User $viewer = null): ?Video
    {
        if (!self::artistEligible($artistId, $viewer) || !$videoId) {
            return null;
        }
        $artist = Artist::find($artistId);
        $video = Video::published()->where('visibility', 'public')->visibleTo($viewer)
            ->where('user_id', $artist->user_id)->find($videoId);

        return $video && Releases::eligible($video) ? $video : null;
    }

    public static function version(object $row, int $version): void
    {
        abort_unless((int) $row->version === $version, 409, 'Stale version. Reload before changing this record.');
    }

    public static function hash(array $data): string
    {
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** Caller holds the promotion transaction; the result and domain changes commit together. */
    public static function once(int $actor, string $operation, string $key, array $input, callable $callback): array
    {
        abort_unless(preg_match('/^[\x21-\x7e]{8,200}$/D', $key), 422, 'Supply an Idempotency-Key of 8–200 visible ASCII characters.');
        $hash = self::hash($input);
        $keyHash = hash('sha256', $key);
        $existing = self::table('operations')->where('user_id', $actor)->where('operation', $operation)->where('key_hash', $keyHash)->first();
        if ($existing) {
            abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency key was used with different input.');

            return json_decode($existing->result, true, flags: JSON_THROW_ON_ERROR);
        }
        $result = $callback();
        DB::table('promotion_operations')->insert([
            'user_id' => $actor, 'environment' => config('billing.environment'), 'operation' => $operation,
            'key_hash' => $keyHash, 'request_hash' => $hash, 'result' => json_encode($result, JSON_THROW_ON_ERROR),
            'created_at' => Billing::timestamp(),
        ]);

        return $result;
    }

    public static function reserveSlot(object $boost, string $start, string $end): void
    {
        for ($slot = 1; $slot <= (int) config('promotions.capacity'); $slot++) {
            $occupied = self::table('slots')->where('surface', $boost->surface)->where('slot', $slot)
                ->whereNull('released_at')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->exists();
            if (!$occupied) {
                DB::table('promotion_slots')->insert([
                    'boost_id' => $boost->id, 'environment' => $boost->environment, 'surface' => $boost->surface,
                    'slot' => $slot, 'starts_at' => $start, 'ends_at' => $end,
                ]);

                return;
            }
        }
        abort(409, 'No rotation slot is available for the requested window.');
    }

    public static function releaseSlot(int $id): void
    {
        DB::table('promotion_slots')->where('boost_id', $id)->whereNull('released_at')->update(['released_at' => Billing::timestamp()]);
    }
}
