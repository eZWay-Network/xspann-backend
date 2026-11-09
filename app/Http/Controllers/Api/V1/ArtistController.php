<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Artists\{PutArtistLinksRequest, PutArtistRequest};
use App\Http\Resources\{ArtistPlaylistResource, ArtistResource, UserResource, VideoResource};
use App\Models\{Artist, ArtistGenre, ArtistPlaylist, Follow, User, Video};
use App\Services\{ArtistCapabilities, Artists, ArtistStatistics, SocialNotifications};
use Spark\Http\{Request, Response};
use Spark\Http\Resources\JsonResource;

class ArtistController extends Controller
{
    public function taxonomy(): Response
    {
        return json([
            'data' => [
                'genres' => ArtistGenre::where('archived', false)->orderByRaw('position ASC, id ASC')->get()->map(fn($genre) => [
                    'id' => $genre->id,
                    'name' => $genre->name,
                    'slug' => $genre->slug,
                ])->all(),
                'playlist_categories' => config('artists.playlist_categories'),
                'link_types' => config('artists.link_types'),
            ]
        ]);
    }

    public function index(Request $request): JsonResource
    {
        $filters = $request->validate([
            ...self::pageRules(),
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'genre_id' => ['sometimes', 'required', 'integer', 'min:1', ['exists' => ['artist_genres', 'id']]],
            'sort' => ['sometimes', 'required', 'string', 'in:latest,popular'],
        ])->all();
        $query = Artist::visibleTo($request->user())->where('artists.listed', true)
            ->withApiData($request->user());
        $search = trim($filters['q'] ?? '');

        if ($search !== '') {
            $pattern = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $query->where(fn($query) => $query
                ->whereRaw("LOWER(artists.stage_name) LIKE LOWER(:artist_name) ESCAPE '!'", ['artist_name' => $pattern])
                ->orWhereRaw("LOWER(artists.slug) LIKE LOWER(:artist_slug) ESCAPE '!'", ['artist_slug' => $pattern]));
        }

        if (isset($filters['genre_id'])) {
            $query->whereHas('genres', fn($query) => $query->where('artist_genres.id', $filters['genre_id']));
        }

        $order = 'artists.created_at DESC, artists.id DESC';

        if (($filters['sort'] ?? 'latest') === 'popular') {
            $query->selectSub(Follow::selectRaw('COUNT(*)')->whereColumn('following_id', 'artists.user_id'), 'fan_count');
            $order = 'fan_count DESC, ' . $order;
        }

        return ArtistResource::collection($query->orderByRaw($order)->paginate((int) ($filters['limit'] ?? 20)));
    }

    public function highlights(Request $request): Response
    {
        $viewer = $request->user();
        $listed = Artist::visibleTo($viewer)->where('artists.listed', true);

        $videos = Video::select('videos.*')
            ->published()
            ->visibleTo($viewer)
            ->where('videos.visibility', 'public')
            ->whereNotNull('videos.storage_path')
            ->whereIn('videos.user_id', (clone $listed)->select('artists.user_id'))
            ->withApiData($viewer)
            ->selectSub(Artist::select('stage_name')->whereColumn('artists.user_id', 'videos.user_id'), 'stage_name')
            ->selectSub(Artist::select('id')->whereColumn('artists.user_id', 'videos.user_id'), 'artist_id')
            ->orderByRaw('COALESCE(videos.published_at, videos.created_at) DESC, videos.id DESC')
            ->limit(12)
            ->get();

        $playlists = ArtistPlaylist::visibleTo($viewer)
            ->whereIn('artist_playlists.artist_id', (clone $listed)->select('artists.id'))
            ->whereHas('videos', fn($query) => $query->published()->visibleTo($viewer)->where('videos.visibility', 'public'))
            ->withApiData($viewer, publicVideosOnly: true)
            ->selectSub(Artist::select('stage_name')->whereColumn('artists.id', 'artist_playlists.artist_id'), 'stage_name')
            ->selectSub(Artist::select('user_id')->whereColumn('artists.id', 'artist_playlists.artist_id'), 'artist_user_id')
            ->orderByRaw('artist_playlists.created_at DESC, artist_playlists.id DESC')
            ->limit(8)
            ->get();

        return json([
            'data' => [
                'releases' => $videos->map(fn(Video $video) => [
                    'artist_id' => (int) $video->artist_id,
                    'artist_name' => $video->stage_name,
                    'video' => VideoResource::make($video)->resolve($request),
                ])->values()->all(),
                'playlists' => $playlists->map(fn(ArtistPlaylist $playlist) => [
                    'artist_user_id' => (int) $playlist->artist_user_id,
                    'artist_name' => $playlist->stage_name,
                    'playlist' => ArtistPlaylistResource::make($playlist)->resolve($request),
                ])->values()->all(),
            ]
        ])->setHeader('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Artist $artist): JsonResource
    {
        return ArtistResource::make(Artist::visibleTo($request->user())->withApiData($request->user())->findOrFail($artist->id));
    }

    public function bySlug(Request $request, string $slug): JsonResource
    {
        return ArtistResource::make(Artist::visibleTo($request->user())->withApiData($request->user())->where('slug', $slug)->firstOrFail());
    }

    public function videos(Request $request, Artist $artist): JsonResource
    {
        $artist = self::visible($request, $artist);

        return VideoResource::collection(Video::where('user_id', $artist->user_id)->published()
            ->visibleTo($request->user())->withApiData($request->user())
            ->orderByRaw('videos.created_at DESC, videos.id DESC')->paginate(self::limit($request)));
    }

    public function followers(Request $request, Artist $artist): JsonResource
    {
        $artist = self::visible($request, $artist);
        $followers = User::findOrFail($artist->user_id)->followers()->visibleTo($request->user())
            ->withApiData($request->user())->orderByRaw('users.created_at DESC, users.id DESC')
            ->paginate(self::limit($request));

        return UserResource::collection($followers);
    }

    public function follow(Request $request, Artist $artist, SocialNotifications $notifications): Response
    {
        $artist = self::visible($request, $artist);

        return app(FollowController::class)->store($request, User::findOrFail($artist->user_id), $notifications)
            ->setStatusCode(Response::STATUS_OK);
    }

    public function unfollow(Request $request, Artist $artist): Response
    {
        $artist = self::visible($request, $artist);

        return app(FollowController::class)->destroy($request, User::findOrFail($artist->user_id));
    }

    public function mine(Request $request): JsonResource
    {
        return $this->ownerResource($request, Artists::owned($request->user()));
    }

    public function save(PutArtistRequest $request, Artists $artists): Response
    {
        $artist = $artists->save($request->user(), $request->validated()->all());

        return $this->ownerResource($request, $artist)->response($artist->wasCreated() ? Response::STATUS_CREATED : Response::STATUS_OK);
    }

    public function links(PutArtistLinksRequest $request, Artists $artists): JsonResource
    {
        return $this->ownerResource($request, $artists->replaceLinks($request->user(), $request->validated('links')));
    }

    public function analytics(Request $request, ArtistCapabilities $capabilities): Response
    {
        return json(['data' => ArtistStatistics::overview(Artists::owned($request->user()), $capabilities)]);
    }

    public function fans(Request $request, ArtistCapabilities $capabilities): Response
    {
        return json(['data' => ArtistStatistics::fans(Artists::owned($request->user()), $capabilities)]);
    }

    private function ownerResource(Request $request, Artist $artist): ArtistResource
    {
        $artist = Artist::withApiData($request->user())->findOrFail($artist->id);
        $artist->owner_view = true;

        return ArtistResource::make($artist);
    }

    public static function visible(Request $request, Artist $artist): Artist
    {
        return Artist::visibleTo($request->user())->findOrFail($artist->id);
    }

    public static function pageRules(): array
    {
        return [
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100000'],
            'limit' => ['sometimes', 'required', 'integer', 'min:1', 'max:50'],
        ];
    }

    public static function limit(Request $request): int
    {
        return (int) ($request->validate(self::pageRules())['limit'] ?? 20);
    }
}
