<?php

namespace Tests\Feature;

use App\Jobs\ProcessPrivacyRequest;
use App\Models\{Artist, ArtistPlaylist, Follow, Report};
use App\Services\{ArtistStatistics, Moderation, PendingUploads, PrivacyRequests};
use Spark\Facades\Hash;
use Tests\Support\ArtistFixtures;
use Tests\TestCase;

class ArtistLifecycleTest extends TestCase
{
    use ArtistFixtures;

    public function testStatisticsCollectObservedSnapshotsAndGateTrendsWithoutBackfilling(): void
    {
        $owner = $this->makeUser();
        $fan = $this->makeUser('fan');
        $artist = $this->makeArtist($owner);
        $video = $this->makeVideo($owner, ['views_count' => 10, 'likes_count' => 3]);
        $this->makeVideo($owner, ['visibility' => 'private', 'views_count' => 2]);
        $this->makeVideo($owner, ['moderation_status' => 'restricted', 'views_count' => 100]);
        Follow::create(['follower_id' => $fan->id, 'following_id' => $owner->id]);

        ArtistStatistics::collectAll();
        ArtistStatistics::collectAll();
        $this->assertDatabaseCount('artist_daily_statistics', 1);
        $this->asUser($owner)->getJson('/api/v1/me/artist/analytics')->assertOk()
            ->assertJsonPath('data.totals.views', 12)->assertJsonPath('data.totals.videos', 2)
            ->assertJsonPath('data.totals.followers', 1)->assertJsonPath('data.daily', [])
            ->assertJsonPath('data.trends_available', false);

        $yesterday = gmdate('Y-m-d', time() - 86400);
        query('artist_daily_statistics')->insert([
            'artist_id' => $artist->id, 'date' => $yesterday, 'followers' => 0,
            'collected_at' => $yesterday . ' 20:00:00',
        ]);
        $this->artistPlan($owner, 'artist_starter');
        $this->getJson('/api/v1/me/artist/analytics')->assertOk()->assertJsonPath('data.trends_available', true)
            ->assertJsonCount(2, 'data.daily')->assertJsonPath('data.daily.1.views', 12);
        $this->getJson('/api/v1/me/artist/fans/summary')->assertOk()->assertJsonPath('data.observed_follower_change', 1);
        $video->update(['status' => 'deleted']);
        $this->getJson('/api/v1/me/artist/analytics')->assertOk()->assertJsonPath('data.totals.views', 2)
            ->assertJsonPath('data.daily.1.views', 2);
        $this->assertDatabaseCount('artist_daily_statistics', 2);
        $this->asUser($fan)->getJson('/api/v1/me/artist/analytics')->assertStatus(404);
    }

    public function testArtistImagesOwnershipFallbackPruningAndReplacement(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser('other');
        $artist = $this->makeArtist($owner);
        storage('public')->put('avatars/1/avatar.png', 'avatar');
        $owner->update(['avatar' => 'avatars/1/avatar.png']);
        foreach (['artist-images/1/first.png', 'artist-images/1/next.png', 'artist-images/1/abandoned.png', 'artist-images/2/foreign.png'] as $path) {
            storage('public')->put($path, 'image');
            PendingUploads::track(str_contains($path, '/2/') ? $other->id : $owner->id, $path);
        }
        $this->asUser($owner)->getJson('/api/v1/me/artist')->assertOk()->assertJsonPath('data.image_url', media_url('avatars/1/avatar.png'));
        $profile = ['stage_name' => 'Artist', 'slug' => $artist->slug];

        foreach (['artist-images/2/foreign.png', 'artist-images/1/missing.png', 'avatars/1/avatar.png', 'https://evil.example/image.png'] as $path) {
            $this->putJson('/api/v1/me/artist', [...$profile, 'image' => $path])->assertStatus(422);
        }
        $this->putJson('/api/v1/me/artist', [...$profile, 'image' => 'public:artist-images/1/first.png'])->assertOk();
        query('pending_uploads')->where('id', '>', 0)->update(['created_at' => '2000-01-01 00:00:00']);
        PendingUploads::prune(time() - 3600);
        $this->assertTrue(storage('public')->exists('artist-images/1/first.png'));
        $this->assertFalse(storage('public')->exists('artist-images/1/abandoned.png'));
        storage('public')->put('artist-images/1/next.png', 'next');
        $this->putJson('/api/v1/me/artist', [...$profile, 'image' => 'public:artist-images/1/next.png'])->assertOk();
        $this->assertFalse(storage('public')->exists('artist-images/1/first.png'));
        $this->putJson('/api/v1/me/artist', [...$profile, 'image' => null])->assertOk()
            ->assertJsonPath('data.image_url', media_url('avatars/1/avatar.png'));
        $this->assertFalse(storage('public')->exists('artist-images/1/next.png'));
        $this->assertTrue(storage('public')->exists('avatars/1/avatar.png'));
    }

    public function testPlaylistModerationAndEvidenceSurviveCollectionRemoval(): void
    {
        $owner = $this->makeUser();
        $fan = $this->makeUser('fan');
        $reviewer = $this->makeUser('reviewer');
        $artist = $this->makeArtist($owner);
        $playlist = ArtistPlaylist::create(['artist_id' => $artist->id, 'title' => 'Reported collection'])->refresh();
        $path = "/api/v1/artists/$artist->id/playlists/$playlist->id";
        $this->asUser($owner)->postJson('/api/v1/reports', ['playlist_id' => $playlist->id, 'reason' => 'Own'])->assertStatus(422);
        $this->asUser($fan)->postJson('/api/v1/reports', ['playlist_id' => $playlist->id, 'reported_user_id' => $owner->id, 'reason' => 'Ambiguous'])->assertStatus(422);
        $playlist->update(['visibility' => 'private']);
        $this->postJson('/api/v1/reports', ['playlist_id' => $playlist->id, 'reason' => 'Hidden'])->assertStatus(404);
        $playlist->update(['visibility' => 'public']);
        $id = $this->postJson('/api/v1/reports', ['playlist_id' => $playlist->id, 'reason' => 'Review'])->assertStatus(201)->json('data.id');
        $report = Report::findOrFail($id);
        $this->assertSame($owner->id, $report->subject_user_id);
        $this->assertFalse(str_contains(query('reports')->where('id', $id)->first()->evidence, 'Reported collection'));
        Moderation::decide($id, $reviewer, ['action' => 'restrict', 'reason' => 'Review confirmed', 'version' => 1]);
        $this->getJson($path)->assertStatus(404);
        $this->getJson("/api/v1/artists/$artist->id/playlists")->assertOk()->assertJsonPath('meta.total', 0);
        Moderation::decide($id, $reviewer, ['action' => 'restore', 'reason' => 'Restored', 'version' => 2]);
        $this->getJson($path)->assertOk();
        $this->asUser($owner)->deleteJson("/api/v1/me/artist/playlists/$playlist->id")->assertStatus(204);
        $this->assertSame(null, $report->refresh()->playlist_id);
        $this->assertSame('Reported collection', json_decode($report->evidence, true)['title']);
    }

    public function testExportAndDeletionCoverAllArtistDataAndImages(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner, ['image' => 'public:artist-images/1/profile.png']);
        storage('public')->put('artist-images/1/profile.png', 'image');
        $video = $this->makeVideo($owner);
        $this->asUser($owner);
        $this->putJson('/api/v1/me/artist/links', ['links' => [['label' => 'Home', 'type' => 'website', 'url' => 'https://example.com']]])->assertOk();
        $id = $this->postJson('/api/v1/me/artist/playlists', ['title' => 'Private collection', 'visibility' => 'private'])->assertStatus(201)->json('data.id');
        $this->putJson("/api/v1/me/artist/playlists/$id/items", ['version' => 1, 'video_ids' => [$video->id]])->assertOk();
        ArtistStatistics::collectAll();
        $proof = $this->postJson('/api/v1/auth/reauthenticate', ['purpose' => 'export_data', 'password' => 'password123'])->assertOk()->json('data.proof');
        $export = $this->postJson('/api/v1/me/export-requests', ['proof' => $proof])->assertStatus(202)->json('data.id');
        (new ProcessPrivacyRequest($export))->handle();
        $data = json_decode(Hash::decrypt(storage('local')->get(PrivacyRequests::exportPath($export))), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($artist->id, $data['artist']['id']);
        $this->assertSame('Home', $data['artist']['links'][0]['label']);
        $this->assertSame('Private collection', $data['artist']['playlists'][0]['title']);
        $this->assertSame($video->id, $data['artist']['playlist_items'][0]['video_id']);
        $this->assertCount(1, $data['artist']['daily_statistics']);

        $proof = $this->postJson('/api/v1/auth/reauthenticate', ['purpose' => 'delete_account', 'password' => 'password123'])->assertOk()->json('data.proof');
        $this->postJson('/api/v1/me/deletion-requests', ['proof' => $proof])->assertStatus(202);
        $this->flushHeaders()->getJson("/api/v1/artists/$artist->id")->assertStatus(404);
        $deletion = query('privacy_requests')->where('kind', 'deletion')->first();
        (new ProcessPrivacyRequest($deletion->id))->handle();

        foreach (['artists', 'artist_links', 'artist_genre_assignments', 'artist_playlists', 'artist_playlist_items', 'artist_daily_statistics'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertFalse(storage('public')->exists('artist-images/1/profile.png'));
        $this->assertFalse(storage('local')->exists(PrivacyRequests::exportPath($export)));
    }

    public function testForwardMigrationPreservesPopulatedBaselineAndConstraints(): void
    {
        $path = dirname(__DIR__, 2) . '/database/migrations/migration_2026_10_31_120000_artists_and_playlists.php';
        (require $path)->down();
        $owner = $this->makeUser();
        $fan = $this->makeUser('fan');
        $video = $this->makeVideo($owner);
        Follow::create(['follower_id' => $fan->id, 'following_id' => $owner->id]);
        $report = Moderation::report($fan, ['video_id' => $video->id, 'reason' => 'Existing report']);
        (require $path)->up();
        $this->assertSame('Existing report', $report->refresh()->reason);
        $this->assertSame('Hello #world', $video->refresh()->caption);
        $this->assertDatabaseCount('follows', 1);
        $artist = $this->makeArtist($owner, ['featured_video_id' => $video->id]);
        $playlist = ArtistPlaylist::create(['artist_id' => $artist->id, 'title' => 'Collection']);
        query('artist_playlist_items')->insert(['playlist_id' => $playlist->id, 'video_id' => $video->id, 'position' => 0]);
        $this->assertThrows(\PDOException::class, fn() => $this->makeArtist($owner, ['slug' => 'another-slug']));
        $this->assertThrows(\PDOException::class, fn() => query('artist_playlist_items')->insert(['playlist_id' => $playlist->id, 'video_id' => $video->id, 'position' => 1]));
        $video->remove();
        $this->assertSame(null, $artist->refresh()->featured_video_id);
        $this->assertDatabaseCount('artist_playlist_items', 0);
        $this->assertSame([], db()->getPdo()->query('PRAGMA foreign_key_check')->fetchAll());
        (require $path)->down();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('reports', 1);
        (require $path)->up();
        $this->assertSame([], db()->getPdo()->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function testArtistImageDeletionRetainsS3CleanupReferencesAcrossFailure(): void
    {
        $server = new \Tests\Support\HttpServer(dirname(__DIR__) . '/Fixtures/s3-router.php', $this->storagePath, [
            'XSPANN_S3_TEST_ROOT' => $this->storagePath,
        ]);

        try {
            $this->useS3(['endpoint' => $server->url, 'use_path_style_endpoint' => true]);
            $owner = $this->makeUser();
            $artist = $this->makeArtist($owner, ['image' => 's3:artist-images/1/profile.png']);
            storage('s3')->put('artist-images/1/profile.png', 'image');
            $this->asUser($owner);
            $proof = $this->postJson('/api/v1/auth/reauthenticate', ['purpose' => 'delete_account', 'password' => 'password123'])
                ->assertOk()->json('data.proof');
            $this->postJson('/api/v1/me/deletion-requests', ['proof' => $proof])->assertStatus(202);
            $record = query('privacy_requests')->where('kind', 'deletion')->first();
            file_put_contents($this->storagePath . '/fail-delete', '1');
            $this->assertThrows(\RuntimeException::class, fn() => (new ProcessPrivacyRequest($record->id))->handle());
            $this->assertDatabaseHas('privacy_requests', ['id' => $record->id, 'status' => 'failed']);
            $this->assertSame(null, $artist->refresh()->image);
            $this->assertTrue(storage('s3')->exists('artist-images/1/profile.png'));
            unlink($this->storagePath . '/fail-delete');
            (new ProcessPrivacyRequest($record->id))->handle();
            $this->assertFalse(storage('s3')->exists('artist-images/1/profile.png'));
            $this->assertDatabaseCount('artists', 0);
            $this->assertDatabaseHas('privacy_requests', ['id' => $record->id, 'status' => 'completed']);
        } finally {
            $server->stop();
        }
    }
}
