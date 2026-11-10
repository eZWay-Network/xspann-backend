<?php

use App\Models\{Artist, Video};
use App\Services\ArtistStatistics;
use Spark\Facades\DB;

return new class {
    private const VIDEO_PREFIX = '20261110-0626-4000-';
    private const PLAYLIST_PREFIX = 'November session archive, volume ';
    private const VOLUMES = 12;

    private const TRACKS = [
        'First Light',
        'Window Glass',
        'After Hours',
        'The Long Way',
        'Side Street',
        'Last Train',
        'Paper Moon',
        'Open Water',
        'Sunday Morning',
        'Slow Return',
        'Small Hours',
        'Half a Mile',
        'Signal Fire',
        'New Weather',
        'Home Again',
    ];

    private const TAKES = ['studio single', 'acoustic version', 'live recording', 'rehearsal take', 'alternate mix'];

    private const PLAYLISTS = [
        'release' => 'The Singles',
        'live' => 'Live Sessions',
        'behind_the_scenes' => 'From the Rehearsal Room',
        'other' => 'Artist Selections',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $artists = $this->artists();

            if (!$artists) {
                throw new RuntimeException('Run the music community seed before the artist content seed.');
            }

            foreach ($artists as $artist) {
                $videoIds = $this->releases($artist);
                $this->playlists($artist, $videoIds);
                ArtistStatistics::collect($artist->id);
            }
        });
    }

    private function artists(): array
    {
        $emails = array_map(fn(int $n) => sprintf('artist%02d@xpn.example.test', $n), range(1, 12));
        $users = DB::table('users')->whereIn('email', $emails)->pluck('id');

        return $users ? Artist::whereIn('user_id', $users)->orderBy('id')->get()->all() : [];
    }

    private function releases(Artist $artist): array
    {
        $clips = Video::published()->visibleTo(null)
            ->where('user_id', $artist->user_id)
            ->whereNull('creation_id')
            ->whereNotNull('storage_path')
            ->orderBy('id')
            ->limit(3)
            ->get()->all();

        if (!$clips) {
            throw new RuntimeException("Seed a playable public community clip for {$artist->stage_name} before running this seed.");
        }

        $seeded = fn() => DB::table('videos')
            ->where('user_id', $artist->user_id)
            ->where('creation_id', 'like', self::VIDEO_PREFIX . '%')
            ->orderBy('creation_id')
            ->pluck('id', 'creation_id');

        $existing = $seeded();
        $rows = [];
        $now = now()->utc();

        $total = count(self::TRACKS) * count(self::TAKES);

        for ($i = 0; $i < $total; $i++) {
            $key = self::VIDEO_PREFIX . sprintf('%04x-%012x', $i, $artist->user_id);
            if (isset($existing[$key])) {
                continue;
            }

            $clip = $clips[$i % count($clips)];
            $track = self::TRACKS[$i % count(self::TRACKS)];
            $take = self::TAKES[intdiv($i, count(self::TRACKS))];
            $posted = $now->copy()->subHours(($total - $i) * 6)->toDateTimeString();

            $rows[] = [
                'user_id' => $artist->user_id,
                'creation_id' => $key,
                'caption' => "{$artist->stage_name} — {$track} ({$take})",
                'location_name' => $artist->location,
                'storage_path' => $clip->storage_path,
                'thumbnail_url' => $clip->thumbnail_url,
                'duration' => $clip->duration,
                'visibility' => 'public',
                'moderation_status' => 'approved',
                'status' => Video::STATUS_PUBLISHED,
                'processing_state' => 'ready',
                'publication_state' => 'published',
                'published_at' => $posted,
                'rights_confirmed_at' => $clip->rights_confirmed_at,
                'rights_version' => $clip->rights_version,
                'created_at' => $posted,
                'updated_at' => $posted,
            ];
        }

        foreach (array_chunk($rows, 30) as $chunk) {
            DB::table('videos')->insert($chunk);
        }

        return array_values($seeded());
    }

    private function playlists(Artist $artist, array $videoIds): void
    {
        $existing = DB::table('artist_playlists')
            ->where('artist_id', $artist->id)
            ->where('description', 'like', self::PLAYLIST_PREFIX . '%')
            ->pluck('id', 'description');

        $now = now()->utc();
        $index = 0;

        for ($volume = 1; $volume <= self::VOLUMES; $volume++) {
            foreach (self::PLAYLISTS as $category => $series) {
                $index++;
                $description = self::PLAYLIST_PREFIX . "{$volume}. {$series} from {$artist->stage_name} in {$artist->location}.";
                if (isset($existing[$description])) {
                    continue;
                }

                $posted = $now->copy()->subHours((self::VOLUMES * count(self::PLAYLISTS) - $index) * 8)->toDateTimeString();
                $playlistId = DB::table('artist_playlists')->insert([
                    'artist_id' => $artist->id,
                    'title' => "{$series}, Vol. {$volume}",
                    'category' => $category,
                    'description' => $description,
                    'created_at' => $posted,
                    'updated_at' => $posted,
                ]);

                // The latest artist selection spans several pages of tracks.
                $size = $volume === self::VOLUMES && $category === 'other' ? count($videoIds) : 5 + $index % 8;
                $items = [];

                for ($position = 0; $position < $size; $position++) {
                    $items[] = [
                        'playlist_id' => $playlistId,
                        'video_id' => $videoIds[($index * 7 + $position) % count($videoIds)],
                        'position' => $position,
                    ];
                }

                DB::table('artist_playlist_items')->insert($items);
            }
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach ($this->artists() as $artist) {
                DB::table('artist_playlists')
                    ->where('artist_id', $artist->id)
                    ->where('description', 'like', self::PLAYLIST_PREFIX . '%')
                    ->delete();

                DB::table('videos')
                    ->where('user_id', $artist->user_id)
                    ->where('creation_id', 'like', self::VIDEO_PREFIX . '%')
                    ->delete();

                ArtistStatistics::collect($artist->id);
            }
        });
    }
};
