<?php

use App\Models\{Artist, ArtistPlaylist, Audio, User, Video};
use App\Services\{ArtistStatistics, PolicyEnrollment, StorageService};
use Spark\Console\Prompt;
use Spark\Facades\DB;

return new class {
    // Stage name, home city, genre, latest track and artist bio.
    private const ARTISTS = [
        ['June Arcade', 'Bristol', 'Electronic', 'Night Bus', 'I build synth lines out of sounds I record on my walk home.'],
        ['River Almanac', 'Asheville', 'Folk', 'Borrowed Porch', 'Two guitars, one ribbon mic and a notebook full of road songs.'],
        ['Mira Vale', 'Dhaka', 'Soul', 'Window Seat', 'Keys and close harmonies from a little room above the market.'],
        ['Copper Finch', 'Leeds', 'Alternative', 'Loose Change', 'A three-piece that rehearses in the back of a bicycle workshop.'],
        ['Isla Mora', 'San Juan', 'Latin', 'Hasta el Martes', 'Percussionist writing songs between Sunday family lunches.'],
        ['Theo North', 'Detroit', 'Hip Hop', 'Last Transfer', 'Drum breaks, dusty jazz records and verses written on the bus.'],
        ['Paper Satellites', 'Melbourne', 'Pop', 'Kitchen Light', 'Bedroom pop made by friends on opposite sides of the city.'],
        ['Sana Reed', 'Chicago', 'Jazz', 'Blue Hour', 'Tenor player collecting late sets and happy accidents.'],
        ['Harbor Pines', 'Halifax', 'Country', 'Ferry Crossing', 'Stories from the docks, played on a well-travelled steel guitar.'],
        ['Low Lantern', 'Lagos', 'World', 'Courtyard', 'Guitars and talking drums from our Friday courtyard sessions.'],
        ['Niko Lane', 'Memphis', 'Blues', 'Second Cup', 'Slide guitar, coffee before soundcheck and a song for the ride home.'],
        ['Static Orchard', 'Seattle', 'Rock', 'Side Door', 'We keep the loud takes and the sound of the room.'],
    ];

    private const LISTENERS = 48;

    public function up(): void
    {
        $password = env('XPN_FIXTURE_PASSWORD', 'test@123456');
        if (!is_string($password) || strlen($password) < 8) {
            throw new RuntimeException('Set a dedicated XPN_FIXTURE_PASSWORD of at least 8 characters on the QA deployment before seeding.');
        }

        $media = $this->media();
        if (!$media['videos']) {
            Prompt::warning('No eligible clips under videos/ on the configured disk. Skipping video posts and playlists.');
        }

        DB::transaction(function () use ($media, $password): void {
            $users = [];
            $artists = [];
            $required = array_filter(config('policies.documents'), fn($policy) => $policy['required']);
            $policies = array_map(fn($policy) => $policy['version'], $required);
            $genres = DB::table('artist_genres')->where('archived', false)->pluck('id', 'name');
            $covers = $media['videos'] ? array_slice($media['thumbnails'], 0, 3 * count(self::ARTISTS)) : [];
            $portraits = [...$media['avatars'], ...$media['artist-images'], ...$covers];

            foreach ($this->accounts() as $index => $email) {
                $profile = self::ARTISTS[$index] ?? null;
                $user = $this->user($index, $email, $password, $profile[4] ?? null, $portraits);
                PolicyEnrollment::accept($user, $policies, 'seed');
                $users[] = $user->id;

                if ($profile) {
                    [$name, $city, $genre, , $bio] = $profile;
                    $artist = Artist::create([
                        'user_id' => $user->id,
                        'stage_name' => $name,
                        'slug' => strtolower(str_replace(' ', '-', $name)),
                        'location' => $city,
                        'bio' => $bio,
                        'image' => $user->avatar,
                        'data_since' => $user->created_at,
                    ]);
                    if (isset($genres[$genre])) {
                        $artist->genres()->sync([$genres[$genre]]);
                    }
                    $artists[] = $artist;
                }
            }

            foreach ($users as $id) {
                $others = array_values(array_diff(array_column($artists, 'user_id'), [$id]));
                shuffle($others);
                foreach (array_slice($others, 0, random_int(3, 6)) as $other) {
                    DB::table('follows')->insert(['follower_id' => $id, 'following_id' => $other]);
                }
            }

            foreach ($artists as $index => $artist) {
                $this->releases($artist, $index, $media, $users);
                ArtistStatistics::collect($artist->id);
            }
        });
    }

    private function accounts(): array
    {
        $emails = [];
        foreach (['artist' => count(self::ARTISTS), 'listener' => self::LISTENERS] as $role => $count) {
            for ($i = 1; $i <= $count; $i++) {
                $emails[] = sprintf('%s%02d@xpn.example.test', $role, $i);
            }
        }

        return $emails;
    }

    private function user(int $index, string $email, string $password, ?string $bio, array $portraits): User
    {
        $first = ['Amara', 'Ben', 'Camila', 'Dev', 'Eli', 'Farah', 'Idris', 'Jules', 'Kira', 'Leo', 'Mai', 'Nora', 'Rafi', 'Tessa', 'Yuna', 'Zane'];
        $last = ['Ali', 'Bell', 'Costa', 'Das', 'Ellis', 'Grant', 'Hart', 'Ito', 'Kim', 'Lane', 'Moss', 'Park', 'Reyes', 'Silva', 'Tran', 'West'];
        $bios = [
            'Usually by the sound desk at small shows.',
            'Collecting live cuts for my Sunday radio hour.',
            'Learning bass one song at a time.',
            'Trading gig photos and listening notes.',
            'Always looking for the opener I missed.',
            'Putting together a mixtape for the train ride home.'
        ];

        $name = $first[array_rand($first)] . ' ' . $last[array_rand($last)];
        $joined = now()->subDays(random_int(32, 90));
        $user = (new User())->fill([
            'name' => $name,
            'username' => strtolower(str_replace(' ', '_', $name)) . ($index + 1),
            'email' => $email,
            'password' => $password,
            'bio' => $bio ?? $bios[array_rand($bios)],
            'avatar' => $portraits ? $portraits[array_rand($portraits)] : null,
            'date_of_birth' => sprintf('%d-%02d-%02d', random_int(1978, 2001), random_int(1, 12), random_int(1, 28)),
            'onboarded_at' => $joined,
            'email_verified_at' => $joined,
        ]);
        $user->created_at = $joined;
        if (!$user->save()) {
            throw new RuntimeException("Could not create $email.");
        }

        return $user;
    }

    private function media(): array
    {
        $disk = StorageService::diskName();
        $types = [
            'videos' => ['mp4', 'webm', 'mov'],
            'thumbnails' => ['jpg', 'jpeg', 'webp', 'png'],
            'avatars' => ['jpg', 'jpeg', 'webp', 'png'],
            'artist-images' => ['jpg', 'jpeg', 'webp', 'png'],
            'sounds' => ['m4a', 'mp3', 'ogg', 'wav', 'aac'],
        ];
        $pools = array_fill_keys(array_keys($types), []);
        $blocked = $this->unavailableFiles($disk);
        $config = config("storage.disks.$disk");
        if ($config['driver'] === 'local' && empty($config['url'])) {
            // The signed local media route serves published clips, their JPEG covers and sounds.
            $types = ['videos' => ['mp4', 'webm'], 'thumbnails' => ['jpg', 'jpeg'], 'sounds' => ['m4a', 'mp3']];
        }

        // allFiles() calls the S3 driver's listFiles() through every page and also supports local disks.
        foreach (storage()->allFiles() as $key) {
            $folder = explode('/', $key, 2)[0];
            $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
            if (
                isset($types[$folder]) && !isset($blocked[$key]) && in_array($extension, $types[$folder], true)
                && preg_match('~^[^/]+/[1-9][0-9]*/[a-z0-9_-][a-z0-9._-]*$~iD', $key)
            ) {
                $pools[$folder][] = "$disk:$key";
            }
        }

        foreach ($pools as &$files) {
            shuffle($files);
        }

        return $pools;
    }

    private function unavailableFiles(string $disk): array
    {
        $blocked = DB::table('pending_uploads')->where('disk', $disk)->pluck('path');
        $privateVideos = Video::whereNotIn('id', Video::select('id')->published()->visibleTo(null));
        $references = [
            ...$privateVideos->pluck('storage_path'),
            ...$privateVideos->pluck('thumbnail_url'),
            ...Audio::whereNotIn('id', Audio::select('id')->availableTo(null))->pluck('storage_path'),
            ...User::whereNotIn('id', User::select('id')->visibleTo(null))->pluck('avatar'),
            ...Artist::whereNotIn('id', Artist::select('id')->visibleTo(null))->pluck('image'),
        ];
        foreach ($references as $reference) {
            $location = StorageService::location($reference);
            if ($location && $location['disk'] === $disk) {
                $blocked[] = $location['key'];
            }
        }

        return array_fill_keys($blocked, true);
    }

    private function releases(Artist $artist, int $index, array $media, array $users): void
    {
        $track = self::ARTISTS[$index][3];
        if ($media['sounds']) {
            Audio::create([
                'user_id' => $artist->user_id,
                'title' => "$track (demo)",
                'status' => 'ready',
                'origin' => 'upload',
                'storage_path' => $media['sounds'][$index % count($media['sounds'])],
                'reuse_allowed' => true,
                'rights_version' => config('policies.rights_version'),
                'rights_confirmed_at' => now(),
            ]);
        }

        if (!$media['videos']) {
            return;
        }

        $playlist = ArtistPlaylist::create([
            'artist_id' => $artist->id,
            'title' => "$track sessions",
            'category' => 'behind_the_scenes',
            'description' => "From the first rehearsal to the live take in {$artist->location}.",
        ]);

        $captions = [
            "First pass at $track. Still working out the ending. #rehearsal",
            "Tried a different intro for $track this afternoon. Which take would you keep? #studio",
            "$track, live in {$artist->location}. Thanks to everyone who stayed for the last song. #live"
        ];
        foreach ($captions as $position => $caption) {
            $slot = $index * count($captions) + $position;
            $posted = now()->subDays(19 - 8 * $position + random_int(0, 2))->subMinutes(random_int(0, 1439));
            $videoId = DB::table('videos')->insert([
                'user_id' => $artist->user_id,
                'caption' => $caption,
                'location_name' => $artist->location,
                'storage_path' => $media['videos'][$slot % count($media['videos'])],
                'thumbnail_url' => $media['thumbnails'] ? $media['thumbnails'][$slot % count($media['thumbnails'])] : null,
                'status' => Video::STATUS_PUBLISHED,
                'processing_state' => 'ready',
                'publication_state' => 'published',
                'published_at' => $posted->copy()->utc()->toDateTimeString(),
                'created_at' => $posted,
                'updated_at' => $posted,
                'rights_confirmed_at' => $posted,
                'rights_version' => config('policies.rights_version'),
            ]);
            DB::table('artist_playlist_items')->insert(['playlist_id' => $playlist->id, 'video_id' => $videoId, 'position' => $position]);
            $this->engagement($videoId, array_diff($users, [$artist->user_id]), $track);
        }
    }

    private function engagement(int $videoId, array $viewers, string $track): void
    {
        shuffle($viewers);
        $views = random_int(10, 30);
        $likes = random_int(3, $views - 1);
        $saves = random_int(1, $likes);
        $comments = [
            "Would love to hear $track at a show.",
            'That ending caught me off guard.',
            'Coming back to this with headphones.',
            'Please put the full take up!'
        ];
        shuffle($comments);
        $commentCount = random_int(1, 3);
        foreach (array_slice($viewers, 0, $views) as $index => $viewer) {
            $row = ['user_id' => $viewer, 'video_id' => $videoId];
            DB::table('video_views')->insert($row);
            if ($index < $likes) {
                DB::table('likes')->insert($row);
            }
            if ($index < $saves) {
                DB::table('saves')->insert($row);
            }
            if ($index < $commentCount) {
                DB::table('comments')->insert([...$row, 'body' => $comments[$index]]);
            }
        }
        DB::table('videos')->where('id', $videoId)->update([
            'views_count' => $views,
            'likes_count' => $likes,
            'saves_count' => $saves,
            'comments_count' => $commentCount,
        ]);
    }

    public function down(): void
    {
        DB::table('users')->whereIn('email', $this->accounts())->delete();
    }
};
