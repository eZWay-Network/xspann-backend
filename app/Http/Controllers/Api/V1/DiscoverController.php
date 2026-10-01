<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Resources\VideoResource;
use App\Models\User;
use App\Models\Video;
use Spark\Database\QueryBuilder;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

class DiscoverController extends Controller
{
    public function index(Request $request): JsonResource
    {
        $filters = $this->filters($request, true);

        $videos = Video::published()
            ->where('videos.visibility', 'public')
            ->visibleTo($request->user())
            ->withApiData($request->user());

        if ($search = trim($filters->safe('q') ?: '') !== '') {
            $pattern = $this->pattern($search);
            $creators = $this->matchingUsers(ltrim($search, '@'));

            $videos->where(function (QueryBuilder $query) use ($pattern, $creators): void {
                $query->whereRaw("LOWER(videos.caption) LIKE LOWER(:discover_caption) ESCAPE '!'", ['discover_caption' => $pattern])
                    ->orWhereIn('videos.user_id', $creators->select('users.id'));
            });
        }

        // Explicit ID tie-breaks keep pages stable when timestamps or counters match.
        $order = 'videos.created_at DESC, videos.id DESC';

        if (($filters['sort'] ?? 'popular') === 'popular') {
            $order = '(videos.likes_count + videos.comments_count + videos.saves_count + videos.shares_count) DESC, '
                . 'videos.views_count DESC, ' . $order;
        }

        return VideoResource::collection(
            $videos->orderByRaw($order)->paginate((int) ($filters['limit'] ?? 18))
        );
    }

    public function people(Request $request): JsonResource
    {
        $filters = $this->filters($request);
        $search = ltrim(trim($filters->safe('q') ?: ''), '@');

        $users = User::visibleTo($viewer = $request->user())
            ->withApiData($viewer);

        if ($search !== '') {
            $users->whereIn('users.id', $this->matchingUsers($search)->select('users.id'))
                ->orderByRaw(
                    'CASE WHEN LOWER(users.username) = LOWER(:discover_exact) THEN 0 ELSE 1 END, '
                    . 'followers_count DESC, users.created_at DESC, users.id DESC',
                    ['discover_exact' => $search],
                );
        } else {
            if ($viewer) {
                $users->where('users.id', '!=', $viewer->id)
                    ->whereNotIn('users.id', $viewer->following()->select('users.id'));
            }

            $users->orderByRaw('followers_count DESC, users.created_at DESC, users.id DESC');
        }

        return UserResource::collection($users->paginate((int) ($filters['limit'] ?? 18)));
    }

    private function filters(Request $request, bool $videos = false): \Spark\Http\Input
    {
        $rules = [
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];

        if ($videos) {
            $rules['sort'] = ['sometimes', 'string', 'in:popular,latest'];
        }

        return $request->validate($rules);
    }

    private function matchingUsers(string $search): QueryBuilder
    {
        $pattern = $this->pattern($search);

        return User::where(function (QueryBuilder $query) use ($pattern): void {
            $query->whereRaw("LOWER(users.username) LIKE LOWER(:discover_username) ESCAPE '!'", ['discover_username' => $pattern])
                ->orWhereRaw("LOWER(users.name) LIKE LOWER(:discover_name) ESCAPE '!'", ['discover_name' => $pattern]);
        });
    }

    private function pattern(string $search): string
    {
        // Treat user-entered SQL LIKE wildcards as literal text on every driver.
        return '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
}
