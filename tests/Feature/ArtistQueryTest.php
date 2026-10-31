<?php

namespace Tests\Feature;

use App\Http\Resources\{ArtistPlaylistResource, ArtistResource};
use App\Models\{Artist, ArtistPlaylist};
use Tests\Support\ArtistFixtures;
use Tests\TestCase;

class ArtistQueryTest extends TestCase
{
    use ArtistFixtures;

    public function testArtistAndPlaylistListsHaveBoundedQueriesAndQueryFreeResources(): void
    {
        $viewer = $this->makeUser('viewer');
        $owner = $this->makeUser('owner');
        $artist = $this->makeArtist($owner);

        for ($index = 0; $index < 8; $index++) {
            $user = $this->makeUser('artist' . $index);
            $this->makeArtist($user, ['featured_video_id' => $this->makeVideo($user)->id]);
            $playlist = ArtistPlaylist::create(['artist_id' => $artist->id, 'title' => 'Collection ' . $index]);
            query('artist_playlist_items')->insert([
                'playlist_id' => $playlist->id, 'video_id' => $this->makeVideo($owner)->id, 'position' => 0,
            ]);
        }

        $this->asUser($viewer)->getJson('/api/v1/artists')->assertOk();
        $this->app->mergeConfig(['app' => ['debug' => true]]);
        $queries = 0;
        event()->addListener('app:db.queryExecuted', function () use (&$queries): void {
            $queries++;
        });

        foreach (['/artists?sort=popular', "/artists/$artist->id/playlists?category=other"] as $path) {
            $queries = 0;
            $this->getJson('/api/v1' . $path . '&limit=1')->assertOk();
            $small = $queries;
            $queries = 0;
            $this->getJson('/api/v1' . $path . '&limit=8')->assertOk()->assertJsonCount(8, 'data');
            $this->assertTrue($small > 0 && $small <= 12);
            $this->assertSame($small, $queries);
        }

        $artists = Artist::withApiData($viewer)->get();
        $playlists = ArtistPlaylist::withApiData($viewer)->get();
        $queries = 0;
        ArtistResource::collection($artists)->resolve();
        ArtistPlaylistResource::collection($playlists)->resolve();
        $this->assertSame(0, $queries);
    }
}
