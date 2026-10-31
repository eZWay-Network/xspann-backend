<?php

namespace App\Services;

use App\Models\{Artist, Follow, Video};
use DateTimeImmutable;
use DateTimeZone;
use Spark\Facades\DB;
use function count;

class ArtistStatistics
{
    public const METRICS = ['followers', 'videos', 'views', 'likes', 'comments', 'saves', 'shares'];

    public static function totals(Artist $artist): array
    {
        $row = Video::where('user_id', $artist->user_id)->published()
            ->where('moderation_status', 'approved')
            ->selectRaw('COUNT(*) AS videos, COALESCE(SUM(views_count), 0) AS views, '
                . 'COALESCE(SUM(likes_count), 0) AS likes, COALESCE(SUM(comments_count), 0) AS comments, '
                . 'COALESCE(SUM(saves_count), 0) AS saves, COALESCE(SUM(shares_count), 0) AS shares')
            ->first();

        $totals = ['followers' => Follow::where('following_id', $artist->user_id)->count()];

        foreach (array_slice(self::METRICS, 1) as $metric) {
            $totals[$metric] = (int) ($row?->$metric ?? 0);
        }

        return $totals;
    }

    public static function collect(int $artistId): void
    {
        DB::transaction(function () use ($artistId): void {
            // Serialize repeated workers and on-demand collection on SQLite as well.
            Artist::increment('id', 0, where: ['id' => $artistId]);
            $artist = Artist::visibleTo(null)->find($artistId);

            if (!$artist) {
                return;
            }

            $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $totals = self::totals($artist);

            DB::table('artist_daily_statistics')->upsert([
                'artist_id' => $artist->id,
                'date' => $at->format('Y-m-d'),
                ...$totals,
                'collected_at' => $at->format('Y-m-d H:i:s'),
            ], ['artist_id', 'date'], [...self::METRICS, 'collected_at']);
        });
    }

    public static function collectAll(): void
    {
        $lastId = 0;

        do {
            $artists = Artist::visibleTo(null)->where('artists.id', '>', $lastId)->orderBy('artists.id')->limit(100)->get();

            foreach ($artists as $artist) {
                $lastId = $artist->id;
                self::collect($artist->id);
            }
        } while (count($artists) === 100);
    }

    public static function overview(Artist $artist, ArtistCapabilities $capabilities): array
    {
        self::collect($artist->id);
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $start = $today->modify('-29 days');
        $permitted = $capabilities->forUser($artist->user_id)['analytics_30_days'];
        $series = [];

        if ($permitted) {
            $rows = DB::table('artist_daily_statistics')->where('artist_id', $artist->id)
                ->where('date', '>=', $start->format('Y-m-d'))->where('date', '<=', $today->format('Y-m-d'))
                ->orderBy('date')->get();

            foreach ($rows as $row) {
                $point = ['date' => $row->date, 'collected_at' => \Spark\Carbon::parse($row->collected_at, 'UTC')->toIsoUtcString()];

                foreach (self::METRICS as $metric) {
                    $point[$metric] = (int) $row->$metric;
                }

                $series[] = $point;
            }
        }

        return [
            'totals' => self::totals($artist),
            'data_since' => $artist->data_since->toIsoUtcString(),
            'timezone' => 'UTC',
            'trends_available' => $permitted,
            'period' => ['from' => $start->format('Y-m-d'), 'to' => $today->format('Y-m-d')],
            'daily' => $series,
            'metric_definitions' => [
                'followers' => 'Current account follow relationships, shared with the social API; not announcement consent.',
                'videos' => 'Currently published, approved owned videos, including private and followers-only videos in these owner-only totals.',
                'views' => 'Existing six-hour deduplicated video view records; not unique people, watch time or billable impressions.',
                'engagement' => 'Current stored likes, comments, saves and shares on the included videos.',
                'daily' => 'Last collected cumulative snapshot for each UTC day, not events during that day. Missing days have no observation; no historical backfill. Totals can decrease after deletion, moderation or removed activity.',
            ],
        ];
    }

    public static function fans(Artist $artist, ArtistCapabilities $capabilities): array
    {
        $overview = self::overview($artist, $capabilities);
        $points = $overview['daily'];
        $change = count($points) > 1 ? end($points)['followers'] - $points[0]['followers'] : null;

        return [
            'followers_count' => $overview['totals']['followers'],
            'announcement_subscribers_count' => Announcements::audience($artist->id)->count(),
            'announcement_subscriptions_available' => true,
            'data_since' => $overview['data_since'],
            'trends_available' => $overview['trends_available'],
            'period' => $overview['period'],
            'observed_follower_change' => $change,
            'change_from' => count($points) > 1 ? $points[0]['collected_at'] : null,
            'change_to' => count($points) > 1 ? end($points)['collected_at'] : null,
        ];
    }
}
