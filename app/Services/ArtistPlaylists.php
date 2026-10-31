<?php

namespace App\Services;

use App\Models\{Artist, ArtistPlaylist, User, Video};
use Spark\Facades\DB;
use function count;

class ArtistPlaylists
{
    public function __construct(private Artists $artists, private ArtistCapabilities $capabilities)
    {
    }

    public function create(User $owner, array $input): ArtistPlaylist
    {
        return DB::transaction(function () use ($owner, $input): ArtistPlaylist {
            $artist = $this->artists->lock($owner);

            if ($artist->playlists()->count() >= $this->capabilities->forUser($owner->id)['playlists']) {
                $this->capabilities->deny('playlists');
            }

            return ArtistPlaylist::create([
                ...$input,
                'title' => trim($input['title']),
                'artist_id' => $artist->id,
            ])->refresh();
        });
    }

    public function update(User $owner, int $playlistId, array $input, bool $items = false): ArtistPlaylist
    {
        return DB::transaction(function () use ($owner, $playlistId, $input, $items): ArtistPlaylist {
            $artist = $this->artists->lock($owner);
            $playlist = $artist->playlists()->whereKey($playlistId)->lockForUpdate()->firstOrFail();

            if ($this->capabilities->playlistsReadOnly($artist)) {
                $this->capabilities->deny('playlists');
            }

            abort_if($playlist->version !== (int) $input['version'], 409, 'Playlist version conflict. Reload before editing.');

            if ($items) {
                $ids = array_map('intval', $input['video_ids']);
                abort_unless(count($ids) === count(array_unique($ids)), 422, 'Playlist video IDs must be unique.');
                $available = $ids ? count(Video::select('id')->where('user_id', $owner->id)
                    ->where('status', '!=', Video::STATUS_DELETED)->whereIn('id', $ids)
                    ->orderBy('id')->lockForUpdate()->get()) : 0;
                abort_unless($available === count($ids), 422, 'Every playlist video must belong to this artist and must not be deleted.');

                DB::table('artist_playlist_items')->where('playlist_id', $playlist->id)->delete();

                foreach ($ids as $position => $videoId) {
                    DB::table('artist_playlist_items')->insert([
                        'playlist_id' => $playlist->id,
                        'video_id' => $videoId,
                        'position' => $position,
                    ]);
                }
            }

            $attributes = $items ? [] : array_diff_key($input, ['version' => true]);
            if (isset($attributes['title'])) {
                $attributes['title'] = trim($attributes['title']);
            }

            $changed = ArtistPlaylist::whereKey($playlist->id)->where('version', (int) $input['version'])->update([
                ...$attributes,
                'version' => $playlist->version + 1,
                'updated_at' => now(),
            ]);

            abort_unless($changed === 1, 409, 'Playlist version conflict. Reload before editing.');

            return $playlist->refresh();
        });
    }

    public function delete(User $owner, int $playlistId): void
    {
        DB::transaction(function () use ($owner, $playlistId): void {
            $artist = $this->artists->lock($owner);
            $playlist = $artist->playlists()->whereKey($playlistId)->firstOrFail();

            // Removal stays available after downgrade, including restricted collections.
            if (!$playlist->remove()) {
                throw new \RuntimeException('Playlist could not be deleted.');
            }
        });
    }

    public static function detail(ArtistPlaylist $playlist, Artist $artist, ?User $viewer, bool $owner = false): ArtistPlaylist
    {
        $query = ArtistPlaylist::where('artist_id', $artist->id)->withApiData($viewer);

        if ($owner) {
            abort_unless($viewer?->id === $artist->user_id, 404);
        } else {
            // Visibility may change after route binding or the controller's initial check.
            $query->visibleTo($viewer);
        }

        $playlist = $query->findOrFail($playlist->id);
        $playlist->owner_view = $owner;
        $playlist->read_only = $owner && app(ArtistCapabilities::class)->playlistsReadOnly($artist);
        $playlist->setRelation('videos', $playlist->videos()->published()->visibleTo($viewer)
            ->where('videos.user_id', $artist->user_id)
            ->withApiData($viewer)->orderBy('artist_playlist_items.position')->get());

        if ($owner) {
            // Metadata only for unfinished/restricted items; no premature media URLs.
            $playlist->setRelation('items', DB::table('artist_playlist_items')
                ->join('videos', 'videos.id', '=', 'artist_playlist_items.video_id')
                ->select(['video_id', 'position', 'videos.status', 'videos.visibility', 'videos.moderation_status'])
                ->where('playlist_id', $playlist->id)->orderBy('position')->get());
        }

        return $playlist;
    }
}
