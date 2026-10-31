<?php

namespace Tests\Feature;

use App\Models\{ArtistPlaylist, Follow, Video};
use Tests\Support\ArtistFixtures;
use Tests\TestCase;

class ArtistPlaylistTest extends TestCase
{
    use ArtistFixtures;

    public function testOwnedReorderingConflictsAndForeignItemsAreAtomic(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser('other');
        $artist = $this->makeArtist($owner);
        $this->makeArtist($other);
        $one = $this->makeVideo($owner);
        $two = $this->makeVideo($owner);
        $foreign = $this->makeVideo($other);
        $this->asUser($owner);
        $id = $this->postJson('/api/v1/me/artist/playlists', ['title' => 'Release', 'category' => 'release', 'genre_id' => 1])
            ->assertStatus(201)->assertJsonPath('data.version', 1)->json('data.id');
        $path = "/api/v1/me/artist/playlists/$id";
        $this->putJson($path . '/items', ['video_ids' => [$one->id, $two->id], 'version' => 1])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.videos.0.id', $one->id);
        $this->putJson($path . '/items', ['video_ids' => [$two->id, $one->id], 'version' => 2])
            ->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.videos.0.id', $two->id);
        $this->putJson($path . '/items', ['video_ids' => [$one->id], 'version' => 2])->assertStatus(409);
        $this->patchJson($path, ['title' => 'Stale', 'version' => 2])->assertStatus(409);

        foreach ([[$one->id, $one->id], [$one->id, $foreign->id], [999999], array_fill(0, 101, $one->id)] as $ids) {
            $this->putJson($path . '/items', ['video_ids' => $ids, 'version' => 3])->assertStatus(422);
        }
        $this->getJson($path)->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.videos.0.id', $two->id);
        $this->assertDatabaseCount('artist_playlist_items', 2);
        $this->getJson("/api/v1/artists/$artist->id/playlists?genre_id=1&category=release")->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson("/api/v1/artists/$artist->id/playlists?category=live")->assertOk()->assertJsonPath('meta.total', 0);

        $this->asUser($other);
        $this->getJson($path)->assertStatus(404);
        $this->patchJson($path, ['title' => 'Stolen', 'version' => 3])->assertStatus(404);
        $this->putJson($path . '/items', ['video_ids' => [$foreign->id], 'version' => 3])->assertStatus(404);
        $this->deleteJson($path)->assertStatus(404);

        $this->asUser($owner)->putJson($path . '/items', ['video_ids' => [], 'version' => 3])
            ->assertOk()->assertJsonPath('data.videos', [])->assertJsonPath('data.version', 4);
        $this->deleteJson($path)->assertStatus(204);
        $this->assertDatabaseCount('artist_playlist_items', 0);
        $this->assertDatabaseCount('videos', 3);
    }

    public function testPlaylistContentsCountsAndCoversAlwaysFollowVisibility(): void
    {
        $owner = $this->makeUser();
        $fan = $this->makeUser('fan');
        $stranger = $this->makeUser('stranger');
        $artist = $this->makeArtist($owner);
        $otherArtist = $this->makeArtist($stranger);
        $private = $this->makeVideo($owner, ['visibility' => 'private', 'thumbnail_url' => 'thumbnails/1/private.jpg']);
        $followers = $this->makeVideo($owner, ['visibility' => 'followers', 'thumbnail_url' => 'thumbnails/1/followers.jpg']);
        $public = $this->makeVideo($owner, ['thumbnail_url' => 'thumbnails/1/public.jpg']);
        $processing = $this->makeVideo($owner, ['status' => Video::STATUS_PROCESSING]);
        $restricted = $this->makeVideo($owner, ['moderation_status' => 'restricted']);
        $this->asUser($owner);
        $id = $this->postJson('/api/v1/me/artist/playlists', ['title' => 'Visibility'])->assertStatus(201)->json('data.id');
        $path = "/api/v1/me/artist/playlists/$id";
        $this->putJson($path . '/items', ['version' => 1, 'video_ids' => [$private->id, $followers->id, $public->id, $processing->id, $restricted->id]])
            ->assertOk()->assertJsonCount(5, 'data.items')->assertJsonCount(3, 'data.videos');
        $publicPath = "/api/v1/artists/$artist->id/playlists/$id";

        $this->asUser($stranger)->getJson($publicPath)->assertOk()->assertJsonCount(1, 'data.videos')
            ->assertJsonPath('data.cover_url', media_url('thumbnails/1/public.jpg'))->assertJsonPath('data.videos_count', 1);
        $this->getJson("/api/v1/artists/$otherArtist->id/playlists/$id")->assertStatus(404);
        $this->getJson("/api/v1/artists/$artist->id/playlists")->assertOk()->assertJsonPath('data.0.videos_count', 1);
        Follow::create(['follower_id' => $fan->id, 'following_id' => $owner->id]);
        $this->asUser($fan)->getJson($publicPath)->assertOk()->assertJsonCount(2, 'data.videos')
            ->assertJsonPath('data.cover_url', media_url('thumbnails/1/followers.jpg'));
        $public->update(['visibility' => 'private']);
        $this->asUser($stranger)->getJson($publicPath)->assertOk()->assertJsonPath('data.videos_count', 0)
            ->assertJsonPath('data.cover_url', null)->assertJsonPath('data.videos', []);
        $this->getJson("/api/v1/artists/$artist->id/videos")->assertOk()->assertJsonPath('meta.total', 0);

        $this->asUser($owner)->patchJson($path, ['visibility' => 'private', 'version' => 2])->assertOk();
        // Public artist routes never expose a private collection, even to its owner.
        $this->getJson($publicPath)->assertStatus(404);
        $this->getJson($path)->assertOk();
        $this->getJson("/api/v1/artists/$artist->id/playlists")->assertOk()->assertJsonPath('meta.total', 0);
        $this->patchJson($path, ['visibility' => 'public', 'version' => 3])->assertOk();
        $this->asUser($fan)->postJson("/api/v1/users/$owner->id/block")->assertStatus(201);
        $this->getJson($publicPath)->assertStatus(404);
        $this->assertDatabaseCount('follows', 0);
        $owner->update(['status' => 'suspended']);
        $this->asUser($stranger)->getJson($publicPath)->assertStatus(404);
    }

    public function testLimitsDowngradeAndExplicitWriteAllowlists(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner);
        $this->asUser($owner);

        foreach ([['title' => '  '], ['title' => null], ['title' => 'Bad', 'artist_id' => $artist->id], ['title' => 'Bad', 'visibility' => null], ['title' => 'Bad', 'category' => 'invalid']] as $input) {
            $this->postJson('/api/v1/me/artist/playlists', $input)->assertStatus(422);
        }

        $id = $this->postJson('/api/v1/me/artist/playlists', ['title' => 'First'])->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/me/artist/playlists', ['title' => 'Over limit'])->assertStatus(403);
        $this->artistPlan($owner, 'artist_starter');
        $second = $this->postJson('/api/v1/me/artist/playlists', ['title' => 'Second'])->assertStatus(201)->json('data.id');
        $this->artistPlan($owner, 'free');
        $this->getJson('/api/v1/me/artist/playlists')->assertOk()->assertJsonPath('data.0.read_only', true);
        $this->getJson("/api/v1/artists/$artist->id/playlists")->assertOk()->assertJsonPath('meta.total', 2);
        $path = "/api/v1/me/artist/playlists/$id";
        $this->patchJson($path, ['title' => 'Changed', 'version' => 1])->assertStatus(403);
        $this->putJson($path . '/items', ['video_ids' => [], 'version' => 1])->assertStatus(403);
        $this->deleteJson("/api/v1/me/artist/playlists/$second")->assertStatus(204);
        $this->patchJson($path, ['title' => 'Changed', 'version' => 1])->assertOk()->assertJsonPath('data.read_only', false);
        $this->patchJson($path, ['title' => 'Missing version'])->assertStatus(422);
        $this->putJson($path . '/items', ['video_ids' => ['named' => 1], 'version' => 2])->assertStatus(422);
        $this->putJson($path . '/items', ['video_ids' => null, 'version' => 2])->assertStatus(422);
        $this->putJson($path . '/items', ['video_ids' => '', 'version' => 2])->assertStatus(422);
        $this->patchJson($path, ['genre_id' => '', 'version' => 2])->assertStatus(422);
        $this->patchJson($path, ['moderation_status' => null, 'version' => 2])->assertStatus(422);
        $this->assertDatabaseCount('artist_playlists', 1);
    }
}
