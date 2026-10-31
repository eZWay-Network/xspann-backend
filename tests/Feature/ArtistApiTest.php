<?php

namespace Tests\Feature;

use App\Models\{Artist, Follow};
use Tests\Support\ArtistFixtures;
use Tests\TestCase;

class ArtistApiTest extends TestCase
{
    use ArtistFixtures;

    public function testEnrollmentUpdatesGenresAndPublicProfileWithoutPrivateFields(): void
    {
        $owner = $this->makeUser();
        $this->getJson('/api/v1/artist-taxonomy')->assertOk()->assertJsonPath('data.genres.0.name', 'Alternative');
        $this->getJson('/api/v1/me/artist')->assertStatus(401);
        $this->asUser($owner)->getJson('/api/v1/me/artist')->assertStatus(404);

        $profile = ['stage_name' => 'Alice Band', 'slug' => 'alice-band', 'genre_ids' => [1, 2]];
        $artist = $this->putJson('/api/v1/me/artist', $profile)->assertStatus(201)
            ->assertJsonPath('data.capabilities.plan', 'free')->assertJsonPath('data.genres.1.id', 2)->json('data');
        $this->putJson('/api/v1/me/artist', [...$profile, 'bio' => 'Live music', 'genre_ids' => []])
            ->assertOk()->assertJsonPath('data.id', $artist['id'])->assertJsonPath('data.genres', []);
        $this->assertDatabaseCount('artists', 1);
        $this->assertDatabaseCount('artist_daily_statistics', 1);
        $public = $this->getJson('/api/v1/artists/by-slug/alice-band')->assertOk()->json('data');
        $this->assertSame($public, $this->getJson('/api/v1/artists/' . $artist['id'])->assertOk()->json('data'));

        foreach (['email', 'date_of_birth', 'capabilities', 'image', 'moderation_status', 'featured_video_id', 'data_since'] as $field) {
            $this->assertFalse(array_key_exists($field, $public));
        }

        $this->putJson('/api/v1/me/artist', [...$profile, 'slug' => 'alice-new'])->assertOk();
        $this->getJson('/api/v1/artists/by-slug/alice-band')->assertStatus(404);
        $this->getJson('/api/v1/artists/by-slug/alice-new')->assertOk()->assertJsonPath('data.id', $artist['id']);
        $this->putJson('/api/v1/me/artist', [...$profile, 'listed' => false])->assertOk()->assertJsonPath('data.listed', false);
        $this->getJson('/api/v1/artists')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function testValidationUniqueOwnershipAndEligibilityCannotBeBypassed(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $this->makeArtist($alice);
        $this->asUser($bob);
        $profile = ['stage_name' => 'Bob', 'slug' => 'bob-music'];

        foreach ([
            ['slug' => 'alice-music'], ['slug' => 'Bad Slug'], ['stage_name' => '  '],
            ['stage_name' => str_repeat('a', 121)], ['bio' => str_repeat('a', 2001)],
            ['genre_ids' => [1, 1]], ['genre_ids' => [1, 2, 3, 4, 5, 6]], ['genre_ids' => [999]],
            ['genre_ids' => ['named' => 1]], ['genre_ids' => null], ['listed' => null],
            ['genre_ids' => ''], ['genre_ids' => ' '], ['featured_video_id' => ''],
            ['user_id' => $alice->id], ['moderation_status' => null], ['plan' => 'artist_pro'],
        ] as $invalid) {
            $this->putJson('/api/v1/me/artist', [...$profile, ...$invalid])->assertStatus(422);
        }

        $this->assertDatabaseCount('artists', 1);
        $this->putJson('/api/v1/me/artist', $profile)->assertStatus(201)->assertJsonPath('data.user_id', $bob->id);
        $bob->update(['date_of_birth' => now()->subYears(16)->toDateString()]);
        $this->putJson('/api/v1/me/artist', $profile)->assertStatus(403);
        $bob->update(['date_of_birth' => '1990-01-01', 'status' => 'suspended']);
        $this->putJson('/api/v1/me/artist', $profile)->assertStatus(403);
    }

    public function testDirectorySearchGenrePopularityAndPaginationRespectBlocks(): void
    {
        $viewer = $this->makeUser('viewer');
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $a = $this->makeArtist($alice, ['stage_name' => '100% Rock']);
        $b = $this->makeArtist($bob, ['stage_name' => 'Jazz Band']);
        $a->genres()->sync([1]);
        $b->genres()->sync([2]);
        Follow::create(['follower_id' => $viewer->id, 'following_id' => $alice->id]);

        $this->getJson('/api/v1/artists?sort=popular&limit=1')->assertOk()->assertJsonPath('data.0.id', $a->id)->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/artists?sort=latest&limit=1')->assertOk()->assertJsonPath('data.0.id', $b->id);
        $this->getJson('/api/v1/artists?sort=latest&limit=1&page=2')->assertOk()->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/api/v1/artists?q=%25')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/api/v1/artists?q=jAZz&genre_id=2')->assertOk()->assertJsonPath('data.0.id', $b->id);
        $this->getJson('/api/v1/artists?q=jazz&genre_id=1')->assertOk()->assertJsonPath('meta.total', 0);

        foreach (['limit=51', 'limit=0', 'page=0', 'q[]=bad', 'sort=paid', 'genre_id=999'] as $query) {
            $this->getJson('/api/v1/artists?' . $query)->assertStatus(422);
        }

        $this->asUser($viewer)->postJson('/api/v1/users/' . $alice->id . '/block')->assertStatus(201);
        $this->getJson('/api/v1/artists')->assertOk()->assertJsonPath('meta.total', 1);
        foreach (["artists/$a->id", 'artists/by-slug/' . $a->slug, "artists/$a->id/videos", "artists/$a->id/followers", "artists/$a->id/playlists"] as $path) {
            $this->getJson('/api/v1/' . $path)->assertStatus(404);
        }

        $this->asUser($alice)->getJson('/api/v1/artists')->assertOk();
        $b->update(['listed' => false]);
        $this->getJson('/api/v1/artists')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/artists/$b->id")->assertOk();
        $bob->update(['status' => 'deleting']);
        $this->getJson("/api/v1/artists/$b->id")->assertStatus(404);
    }

    public function testFanAliasesReuseFollowsAndNeverEnrollAnnouncements(): void
    {
        $owner = $this->makeUser();
        $fan = $this->makeUser('fan');
        $artist = $this->makeArtist($owner);
        $this->asUser($fan);
        $this->postJson("/api/v1/artists/$artist->id/follow")->assertOk()->assertJsonPath('data.followers_count', 1);
        $this->postJson("/api/v1/artists/$artist->id/follow")->assertOk();
        $this->postJson("/api/v1/users/$owner->id/follow")->assertStatus(201);
        $this->assertDatabaseCount('follows', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->getJson("/api/v1/artists/$artist->id")->assertOk()->assertJsonPath('data.viewer.following', true)
            ->assertJsonPath('data.viewer.updates_subscribed', false);
        $this->getJson("/api/v1/artists/$artist->id/followers")->assertOk()->assertJsonPath('data.0.id', $fan->id);
        $this->asUser($owner)->getJson('/api/v1/me/artist/fans/summary')->assertOk()
            ->assertJsonPath('data.followers_count', 1)->assertJsonPath('data.announcement_subscribers_count', 0);
        $this->postJson("/api/v1/artists/$artist->id/follow")->assertStatus(422);
        $this->asUser($fan)->deleteJson("/api/v1/artists/$artist->id/follow")->assertOk()->assertJsonPath('data.followers_count', 0);
        $this->deleteJson("/api/v1/artists/$artist->id/follow")->assertOk();
    }

    public function testPaidFixturesLimitsFeaturedReleaseAndDowngradePreserveSavedData(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser('other');
        $artist = $this->makeArtist($owner);
        $video = $this->makeVideo($owner);
        $foreign = $this->makeVideo($other);
        $profile = ['stage_name' => 'Artist', 'slug' => $artist->slug];
        $link = ['label' => 'Music', 'type' => 'music', 'url' => 'https://example.com/music'];
        $this->asUser($owner);
        $this->putJson('/api/v1/me/artist/links', ['links' => [$link]])->assertOk();
        $this->putJson('/api/v1/me/artist/links', ['links' => [$link, $link]])->assertStatus(403);
        $this->putJson('/api/v1/me/artist', [...$profile, 'featured_video_id' => $video->id])->assertStatus(403);

        foreach (['http://example.com', 'javascript:alert(1)', 'https://user:pass@example.com', '//example.com'] as $url) {
            $this->putJson('/api/v1/me/artist/links', ['links' => [[...$link, 'url' => $url]]])->assertStatus(422);
        }
        $this->putJson('/api/v1/me/artist/links', ['links' => [[...$link, 'approved' => true]]])->assertStatus(422);
        foreach (['', ' ', null, [null], ['bad-row']] as $invalid) {
            $this->putJson('/api/v1/me/artist/links', ['links' => $invalid])->assertStatus(422);
        }
        $this->artistPlan($owner, 'artist_starter');
        $this->putJson('/api/v1/me/artist/links', ['links' => array_fill(0, 5, $link)])->assertOk()->assertJsonCount(5, 'data.links');
        $this->putJson('/api/v1/me/artist', [...$profile, 'featured_video_id' => $foreign->id])->assertStatus(422);
        $this->putJson('/api/v1/me/artist', [...$profile, 'featured_video_id' => $video->id])->assertOk();
        $this->getJson("/api/v1/artists/$artist->id")->assertOk()->assertJsonPath('data.featured_release.id', $video->id);
        $video->update(['visibility' => 'private']);
        $this->getJson("/api/v1/artists/$artist->id")->assertOk()->assertJsonPath('data.featured_release', null);
        $video->update(['visibility' => 'public']);

        $this->artistPlan($owner, 'free');
        $this->getJson("/api/v1/artists/$artist->id")->assertOk()->assertJsonCount(1, 'data.links')->assertJsonPath('data.featured_release', null);
        $this->getJson('/api/v1/me/artist')->assertOk()->assertJsonCount(5, 'data.links')->assertJsonPath('data.featured_video_id', $video->id);
        $this->putJson('/api/v1/me/artist', [...$profile, 'bio' => 'Still editable'])->assertOk();
        $this->putJson('/api/v1/me/artist', [...$profile, 'featured_video_id' => null])->assertOk();
        $this->putJson('/api/v1/me/artist/links', ['links' => []])->assertOk()->assertJsonPath('data.links', []);
    }

    public function testPaidPlaylistLimitsUseTheSameCapabilityResolver(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner);
        $this->asUser($owner);

        foreach (['artist_starter' => 10, 'artist_pro' => 50] as $plan => $limit) {
            $this->artistPlan($owner, $plan);

            while ($artist->playlists()->count() < $limit - 1) {
                \App\Models\ArtistPlaylist::create(['artist_id' => $artist->id, 'title' => 'Existing collection']);
            }

            $this->postJson('/api/v1/me/artist/playlists', ['title' => 'Last permitted'])->assertStatus(201);
            $this->postJson('/api/v1/me/artist/playlists', ['title' => 'Over limit'])->assertStatus(403);
            $this->assertSame($limit, $artist->playlists()->count());
        }
    }
}
