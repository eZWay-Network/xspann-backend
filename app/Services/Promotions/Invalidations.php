<?php

namespace App\Services\Promotions;

use App\Models\Video;
use App\Services\Billing\Billing;
use Spark\Facades\DB;

class Invalidations
{
    /** Append inside the content/account transaction, without acquiring promotion inventory locks. */
    public static function record(string $type, int $id): void
    {
        $now = Billing::timestamp();
        DB::table('promotion_invalidations')->insert(['target_type' => $type, 'target_id' => $id, 'created_at' => $now]);

        // Foreign keys may later become NULL. Preserve the affected campaign identity now.
        $boosts = DB::table('promotion_boosts');
        switch ($type) {
            case 'audio':
                $boosts->whereIn('video_id', Video::select('id')->where('audio_id', $id));
                break;
            case 'user':
            case 'artist':
            case 'video':
                $boosts->where($type . '_id', $id);
                break;
            case 'period':
                $boosts->whereIn('id', DB::table('promotion_reservations')->select('boost_id')
                    ->whereIn('credit_id', DB::table('promotion_credits')->select('id')->where('period_id', $id)));
                break;
            default:
                return;
        }
        $lastId = 0;
        do {
            $rows = (clone $boosts)->where('id', '>', $lastId)->orderBy('id')->limit(100)->get()->all();
            $cutoffs = self::cutoffs($rows);
            foreach ($rows as $boost) {
                $lastId = $boost->id;
                // Preserve older target-only evidence before deletion severs its lookup path.
                DB::table('promotion_invalidations')->insert([
                    'target_type' => 'boost',
                    'target_id' => $boost->id,
                    'created_at' => $cutoffs[$boost->id] ?? $now
                ]);
            }
        } while (count($rows) === 100);
    }

    public static function revision(): int
    {
        return (int) (DB::table('promotion_invalidations')->max('id') ?? 0);
    }

    public static function cutoff(object $boost): ?string
    {
        $audio = $boost->video_id ? Video::whereKey($boost->video_id)->value('audio_id') : null;
        $periods = DB::table('promotion_credits')->select('period_id')->whereIn('id', DB::table('promotion_reservations')->select('credit_id')->where('boost_id', $boost->id));

        return DB::table('promotion_invalidations')->where('id', '>', $boost->eligibility_revision)
            ->where(fn($q) => $q
                ->where(fn($q) => $q->where('target_type', 'boost')->where('target_id', $boost->id))
                ->orWhere(fn($q) => $q->where('target_type', 'user')->where('target_id', $boost->user_id ?? 0))
                ->orWhere(fn($q) => $q->where('target_type', 'artist')->where('target_id', $boost->artist_id ?? 0))
                ->orWhere(fn($q) => $q->where('target_type', 'video')->where('target_id', $boost->video_id ?? 0))
                ->orWhere(fn($q) => $q->where('target_type', 'audio')->where('target_id', $audio ?? 0))
                ->orWhere(fn($q) => $q->where('target_type', 'period')->whereIn('target_id', $periods)))
            ->orderBy('created_at')->value('created_at');
    }

    public static function cutoffs(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $videos = Video::whereIn('id', array_column($rows, 'video_id'))->get()->keyBy('id');
        $periods = DB::table('promotion_reservations as r')->join('promotion_credits as c', 'c.id', '=', 'r.credit_id')
            ->whereIn('r.boost_id', $ids)->select(['r.boost_id', 'c.period_id'])->get();
        $targets = [];
        foreach ($rows as $row) {
            $targets[$row->id] = [
                'boost:' . $row->id,
                'user:' . ($row->user_id ?? 0),
                'artist:' . ($row->artist_id ?? 0),
                'video:' . ($row->video_id ?? 0),
                'audio:' . (($videos[$row->video_id ?? 0] ?? null)?->audio_id ?? 0)
            ];
        }
        foreach ($periods as $period) {
            if ($period->period_id) {
                $targets[$period->boost_id][] = 'period:' . $period->period_id;
            }
        }
        $typeIds = [];
        foreach ($targets as $keys) {
            foreach ($keys as $key) {
                [$type, $id] = explode(':', $key);
                $typeIds[$type][] = (int) $id;
            }
        }
        $events = DB::table('promotion_invalidations')->where('id', '>', min(array_column($rows, 'eligibility_revision')))
            ->where(function ($query) use ($typeIds): void {
                foreach ($typeIds as $type => $ids) {
                    $query->orWhere(fn($q) => $q->where('target_type', $type)->whereIn('target_id', array_unique($ids)));
                }
            })->orderBy('created_at')->get();
        $cutoffs = [];
        foreach ($rows as $row) {
            foreach ($events as $event) {
                if ($event->id > $row->eligibility_revision && in_array($event->target_type . ':' . $event->target_id, $targets[$row->id], true)) {
                    $cutoffs[$row->id] = $event->created_at;
                    break;
                }
            }
        }

        return $cutoffs;
    }

    public static function placementQuery(): \Spark\Database\QueryBuilder
    {
        return DB::table('promotion_invalidations as pi')->selectRaw('1')
            ->whereColumn('pi.id', '>', 'promotion_placements.eligibility_revision')
            ->where(fn($q) => $q->where(fn($q) => $q->where('pi.target_type', 'artist')->whereColumn('pi.target_id', 'promotion_placements.artist_id'))
                ->orWhere(fn($q) => $q->where('pi.target_type', 'user')->whereColumn('pi.target_id', 'promotion_placements.user_id')));
    }

    public static function placementCutoff(object $row): ?string
    {
        return DB::table('promotion_invalidations')->where('id', '>', $row->eligibility_revision)
            ->where(fn($query) => $query
                ->where(fn($query) => $query->where('target_type', 'artist')->where('target_id', $row->artist_id ?? 0))
                ->orWhere(fn($query) => $query->where('target_type', 'user')->where('target_id', $row->user_id ?? 0)))
            ->orderBy('id')->value('created_at');
    }

}
