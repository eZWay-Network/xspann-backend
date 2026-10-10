<?php

namespace App\Services;

use App\Http\Resources\VideoResource;
use App\Models\Video;
use Spark\Database\QueryBuilder;
use Spark\Exceptions\Hash\DecryptionFailedException;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;
use function array_slice;
use function count;
use function is_array;

class VideoFeed
{
    private const POPULAR_LIMIT = 30;
    private const POPULAR_ORDER = '(videos.likes_count + videos.comments_count + videos.saves_count + videos.shares_count) DESC, videos.views_count DESC, videos.id DESC';

    public function page(Request $request, int $limit, bool $following): JsonResource
    {
        $videos = $this->videos($request, $following)
            ->withApiData($request->user())
            ->withExists(['views as feed_watched' => fn(QueryBuilder $query) => $this->viewer($query, $request)])
            ->orderByRaw(
                'feed_watched ASC, CASE WHEN videos.created_at >= :feed_recent THEN 0 ELSE 1 END ASC, '
                . self::POPULAR_ORDER,
                ['feed_recent' => now()->subDays(7)->toDateTimeString()]
            )
            ->paginate($limit);

        return VideoResource::collection($videos);
    }

    public function cursor(Request $request, int $limit, bool $following, ?string $cursor): JsonResource
    {
        $state = $cursor === null ? $this->start($request, $following) : $this->decode($request, $following, $cursor);
        $ids = [];
        $hasMore = false;

        if ($state['phase'] === 'unwatched') {
            $recent = $this->candidates($request, $following, $state, false)
                ->whereNotIn('videos.id', array_slice($state['popular'], 0, $state['popular_offset']))
                ->where('videos.id', '<', $state['after'])
                ->orderDesc('videos.id')
                ->limit($limit + 1)
                ->pluck('videos.id');

            $remainingPopular = array_slice($state['popular'], $state['popular_offset'], null, true);
            $availablePopular = $this->videos($request, $following)
                ->whereIn('videos.id', array_values($remainingPopular))
                ->where('videos.id', '<', $state['after'])
                ->pluck('videos.id');

            $popular = array_intersect($remainingPopular, $availablePopular);

            // Two recent uploads followed by one popular pick; drain either lane when the other ends.
            while (count($ids) < $limit && ($recent || $popular)) {
                if ($popular && ($state['slot'] === 2 || !$recent)) {
                    $index = array_key_first($popular);
                    $ids[] = (int) $popular[$index];
                    $state['popular_offset'] = $index + 1;
                    $recent = array_values(array_diff($recent, [$popular[$index]]));
                    unset($popular[$index]);
                } else {
                    $state['after'] = (int) array_shift($recent);
                    $ids[] = $state['after'];
                    $popular = array_diff($popular, [$state['after']]);
                }

                $state['slot'] = ($state['slot'] + 1) % 3;
            }

            $hasMore = (bool) ($recent || $popular);

            if (!$hasMore) {
                $state['phase'] = 'watched';
                $state['after'] = $state['max_id'] + 1;
            }
        }

        if ($state['phase'] === 'watched') {
            $remaining = $limit - count($ids);
            $watched = $this->candidates($request, $following, $state, true)
                ->where('videos.id', '<', $state['after'])
                ->orderDesc('videos.id')
                ->limit($remaining + 1)
                ->pluck('videos.id');

            $hasMore = count($watched) > $remaining;

            foreach (array_slice($watched, 0, $remaining) as $id) {
                $state['after'] = (int) $id;
                $ids[] = $state['after'];
            }
        }

        // Hydrate only this page. Recheck visibility and load resource relations in batches.
        $videos = $this->videos($request, $following)
            ->whereIn('videos.id', $ids)
            ->withApiData($request->user())
            ->get()
            ->keyBy('id');

        $items = [];
        foreach ($ids as $id) {
            if (isset($videos[$id])) {
                $items[] = $videos[$id];
            }
        }

        return VideoResource::collection($items)->additional([
            'meta' => [
                'per_page' => $limit,
                'has_more' => $hasMore,
                'next_cursor' => $hasMore ? encrypt($state) : null,
            ],
        ]);
    }

    private function start(Request $request, bool $following): array
    {
        $state = [
            'version' => 1,
            'viewer' => $this->identity($request),
            'following' => $following,
            'expires' => time() + 3600,
            'max_id' => (int) (Video::orderDesc('id')->value('id') ?? 0),
            'view_id' => (int) ($this->viewer(query('video_views'), $request)
                ->orderDesc('id')
                ->value('id') ?? 0),
            'phase' => 'unwatched',
            'slot' => 0,
            'popular' => [],
            'popular_offset' => 0,
        ];

        $state['after'] = $state['max_id'] + 1;
        $state['popular'] = array_map('intval', $this->candidates($request, $following, $state, false)
            ->where('videos.created_at', '>=', now()->subDays(7))
            ->whereRaw('(videos.likes_count + videos.comments_count + videos.saves_count + videos.shares_count + videos.views_count) > 0')
            ->orderByRaw(self::POPULAR_ORDER)
            ->limit(self::POPULAR_LIMIT)
            ->pluck('videos.id'));

        return $state;
    }

    private function decode(Request $request, bool $following, string $cursor): array
    {
        try {
            $state = decrypt($cursor);
        } catch (DecryptionFailedException) {
            abort(422, 'Invalid feed cursor. Refresh the feed.');
        }

        abort_unless(
            is_array($state)
            && ($state['version'] ?? null) === 1
            && ($state['viewer'] ?? null) === $this->identity($request)
            && ($state['following'] ?? null) === $following,
            422,
            'Invalid feed cursor. Refresh the feed.',
        );

        abort_if($state['expires'] <= time(), 410, 'Feed cursor expired. Refresh the feed.');

        return $state;
    }

    private function candidates(Request $request, bool $following, array $state, bool $watched): QueryBuilder
    {
        $views = $this->viewer(query('video_views'), $request)
            ->select('video_id')
            ->where('id', '<=', $state['view_id']);

        $videos = $this->videos($request, $following)->where('videos.id', '<=', $state['max_id']);

        return $watched ? $videos->whereIn('videos.id', $views) : $videos->whereNotIn('videos.id', $views);
    }

    private function videos(Request $request, bool $following): QueryBuilder
    {
        $videos = Video::published()->visibleTo($request->user());

        if ($following) {
            $videos->whereIn('videos.user_id', $request->user()->following()->select('users.id'));
        }

        return $videos;
    }

    private function viewer(QueryBuilder $query, Request $request): QueryBuilder
    {
        if ($user = $request->user()) {
            return $query->where('user_id', $user->id);
        }

        return $query->whereNull('user_id')->where([
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'user_agent_hash' => hash('sha256', (string) $request->useragent()),
        ]);
    }

    private function identity(Request $request): string
    {
        return $request->user()
            ? 'user:' . $request->user('id')
            : hash('sha256', $request->ip() . '|' . $request->useragent());
    }
}
