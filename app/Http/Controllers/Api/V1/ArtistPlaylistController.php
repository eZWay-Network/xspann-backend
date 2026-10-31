<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Artists\{PlaylistRequest, PutPlaylistItemsRequest};
use App\Http\Resources\ArtistPlaylistResource;
use App\Models\{Artist, ArtistPlaylist};
use App\Services\{ArtistCapabilities, ArtistPlaylists, Artists};
use Spark\Http\{Request, Response};
use Spark\Http\Resources\JsonResource;

class ArtistPlaylistController extends Controller
{
    public function index(Request $request, Artist $artist): JsonResource
    {
        $artist = ArtistController::visible($request, $artist);

        return $this->listing($request, $artist);
    }

    public function show(Request $request, Artist $artist, ArtistPlaylist $playlist): JsonResource
    {
        $artist = ArtistController::visible($request, $artist);
        $playlist = ArtistPlaylist::visibleTo($request->user())->where('artist_id', $artist->id)->findOrFail($playlist->id);

        return ArtistPlaylistResource::make(ArtistPlaylists::detail($playlist, $artist, $request->user()));
    }

    public function mine(Request $request): JsonResource
    {
        return $this->listing($request, Artists::owned($request->user()), true);
    }

    public function ownShow(Request $request, ArtistPlaylist $playlist): JsonResource
    {
        $artist = Artists::owned($request->user());
        $playlist = $artist->playlists()->whereKey($playlist->id)->firstOrFail();

        return ArtistPlaylistResource::make(ArtistPlaylists::detail($playlist, $artist, $request->user(), true));
    }

    public function store(PlaylistRequest $request, ArtistPlaylists $playlists): Response
    {
        $playlist = $playlists->create($request->user(), $request->validated()->all());

        return $this->ownShow($request, $playlist)->response(Response::STATUS_CREATED);
    }

    public function update(PlaylistRequest $request, ArtistPlaylist $playlist, ArtistPlaylists $playlists): JsonResource
    {
        return $this->ownShow($request, $playlists->update($request->user(), $playlist->id, $request->validated()->all()));
    }

    public function items(PutPlaylistItemsRequest $request, ArtistPlaylist $playlist, ArtistPlaylists $playlists): JsonResource
    {
        return $this->ownShow($request, $playlists->update($request->user(), $playlist->id, $request->validated()->all(), true));
    }

    public function destroy(Request $request, ArtistPlaylist $playlist, ArtistPlaylists $playlists): Response
    {
        $playlists->delete($request->user(), $playlist->id);

        return response()->noContent();
    }

    private function listing(Request $request, Artist $artist, bool $owner = false): JsonResource
    {
        $filters = $request->validate([
            ...ArtistController::pageRules(),
            'genre_id' => ['sometimes', 'required', 'integer', 'min:1', ['exists' => ['artist_genres', 'id']]],
            'category' => ['sometimes', 'required', 'string', ['in' => config('artists.playlist_categories')]],
        ])->all();
        $query = ArtistPlaylist::where('artist_id', $artist->id)->withApiData($request->user());

        if (!$owner) {
            $query->visibleTo($request->user());
        }

        foreach (['genre_id', 'category'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        $page = $query->orderByRaw('artist_playlists.created_at DESC, artist_playlists.id DESC')
            ->paginate((int) ($filters['limit'] ?? 20));

        if ($owner) {
            $readOnly = app(ArtistCapabilities::class)->playlistsReadOnly($artist);

            foreach ($page->items() as $playlist) {
                $playlist->owner_view = true;
                $playlist->read_only = $readOnly;
            }
        }

        return ArtistPlaylistResource::collection($page);
    }
}
