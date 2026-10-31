<?php

namespace Tests\Feature;

use App\Jobs\{DeleteVideo, ProcessVideo};
use App\Models\{Artist, ArtistPlaylist, Video};
use App\Services\{AccountSecurity, Totp};
use Tests\Support\ArtistFixtures;
use Tests\TestCase;

class ArtistAuditTest extends TestCase
{
    use ArtistFixtures;

    public function testPlaylistDetailRechecksVisibilityWhenLoadingItsResponse(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner);
        $playlist = ArtistPlaylist::create(['artist_id' => $artist->id, 'title' => 'Collection'])->refresh();
        $this->app->mergeConfig(['app' => ['debug' => true]]);
        $pendingChange = null;

        event()->addListener('app:db.queryExecuted', function (string $query) use (&$pendingChange): void {
            if (
                $pendingChange && str_starts_with($query, 'SELECT')
                && str_contains($query, 'artist_playlists') && str_contains($query, 'visibility')
                && !str_contains($query, 'cover_url')
            ) {
                $change = $pendingChange;
                $pendingChange = null;
                $change();
            }
        });

        foreach ([
            fn() => $playlist->update(['visibility' => 'private']),
            fn() => $playlist->update(['moderation_status' => 'restricted']),
            fn() => $owner->update(['status' => 'suspended']),
        ] as $change) {
            $playlist->update(['visibility' => 'public', 'moderation_status' => 'approved']);
            $owner->update(['status' => 'active']);
            $pendingChange = $change;
            $this->getJson("/api/v1/artists/$artist->id/playlists/$playlist->id")->assertStatus(404);
            $this->assertSame(null, $pendingChange);
        }
    }

    public function testDirectoryRejectsEmptyAndInvalidGenreFilters(): void
    {
        foreach (['', ' ', '0', '-1', '1.5', 'unknown', '999999'] as $genre) {
            $this->getJson('/api/v1/artists?genre_id=' . rawurlencode($genre))->assertStatus(422);
        }

        $this->getJson('/api/v1/artists')->assertOk();
        $this->getJson('/api/v1/artists?genre_id=1')->assertOk();
    }

    public function testVideoLifecycleStaysConsistentAcrossArtistsPlaylistsAndFoundationApis(): void
    {
        $owner = $this->makeUser();
        $fan = $this->makeUser('fan');
        $profile = ['stage_name' => 'Artist', 'slug' => 'artist'];
        $artistId = $this->asUser($owner)->putJson('/api/v1/me/artist', $profile)
            ->assertStatus(201)->json('data.id');
        $playlistId = $this->postJson('/api/v1/me/artist/playlists', ['title' => 'Release'])
            ->assertStatus(201)->json('data.id');
        $artistPath = "/api/v1/artists/$artistId";
        $playlistPath = "$artistPath/playlists/$playlistId";
        $ownerPlaylistPath = "/api/v1/me/artist/playlists/$playlistId";

        storage('public')->put('videos/1/source.mp4', pack('N', 24) . 'ftypmp42' . pack('N', 0) . 'mp42isom' . str_repeat("\0", 128));
        $videoId = $this->postJson('/api/v1/videos', [
            ...$this->videoRights(), 'storage_path' => 'videos/1/source.mp4', 'caption' => 'First release',
        ])->assertStatus(201)->assertJsonPath('data.status', 'processing')->json('data.id');
        $this->putJson($ownerPlaylistPath . '/items', ['version' => 1, 'video_ids' => [$videoId]])
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.videos', []);
        $this->flushHeaders()->getJson($playlistPath)->assertOk()->assertJsonPath('data.videos_count', 0)
            ->assertJsonPath('data.cover_url', null)->assertJsonPath('data.videos', []);
        $this->getJson('/api/v1/videos/' . $videoId)->assertStatus(404);

        $this->fakeVideoTools();
        (new ProcessVideo($videoId))->handle();
        $video = Video::findOrFail($videoId);
        $this->assertSame(Video::STATUS_PUBLISHED, $video->status);
        $this->assertTrue($video->audio_id !== null);
        $this->artistPlan($owner, 'artist_starter');
        $this->asUser($owner)->putJson('/api/v1/me/artist', [...$profile, 'featured_video_id' => $videoId])->assertOk();
        $this->asUser($fan)->getJson($artistPath)->assertOk()->assertJsonPath('data.featured_release.id', $videoId);
        $this->getJson($playlistPath)->assertOk()->assertJsonPath('data.videos_count', 1)
            ->assertJsonPath('data.videos.0.id', $videoId)->assertJsonPath('data.cover_url', media_url($this->mediaKey($video->thumbnail_url)));
        $this->getJson('/api/v1/audios/' . $video->audio_id)->assertOk();
        $this->postJson($artistPath . '/follow')->assertOk();
        $this->postJson('/api/v1/users/' . $owner->id . '/follow')->assertStatus(201);
        $this->postJson('/api/v1/videos/' . $videoId . '/view')->assertSuccessful();
        $this->postJson('/api/v1/videos/' . $videoId . '/view')->assertSuccessful();
        $this->postJson('/api/v1/videos/' . $videoId . '/like')->assertSuccessful();
        $this->assertDatabaseCount('follows', 1);
        $this->asUser($owner)->getJson('/api/v1/me/artist/analytics')->assertOk()
            ->assertJsonPath('data.totals.videos', 1)->assertJsonPath('data.totals.views', 1)
            ->assertJsonPath('data.totals.likes', 1)->assertJsonPath('data.totals.followers', 1);

        $report = $this->asUser($fan)->postJson('/api/v1/reports', ['video_id' => $videoId, 'reason' => 'Rights review'])
            ->assertStatus(201)->json('data.id');
        $reviewer = $this->staffToken('reviewer');
        $appealReviewer = $this->staffToken('appealreviewer');
        $decision = $this->withToken($reviewer)->postJson("/api/v1/admin/reports/$report/decision", [
            'action' => 'restrict', 'reason' => 'Rights review pending', 'version' => 1,
        ])->assertOk()->json('data.decision_id');
        $this->asUser($fan)->getJson($artistPath)->assertOk()->assertJsonPath('data.featured_release', null);
        $this->getJson($playlistPath)->assertOk()->assertJsonPath('data.videos_count', 0)
            ->assertJsonPath('data.cover_url', null)->assertJsonPath('data.videos', []);
        $this->getJson($artistPath . '/videos')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/videos/' . $videoId)->assertStatus(404);
        $this->getJson('/api/v1/audios/' . $video->audio_id)->assertStatus(404);
        $this->asUser($owner)->getJson($ownerPlaylistPath)->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.moderation_status', 'restricted')->assertJsonPath('data.videos', []);
        $appeal = $this->postJson('/api/v1/me/moderation-appeals', [
            'decision_id' => $decision, 'explanation' => 'License supplied',
        ])->assertStatus(201)->json('data.id');
        $this->withToken($appealReviewer)->postJson("/api/v1/admin/moderation-appeals/$appeal/decision", [
            'outcome' => 'overturn', 'reason' => 'License confirmed', 'version' => 1,
        ])->assertOk();
        $this->asUser($fan)->getJson($artistPath)->assertOk()->assertJsonPath('data.featured_release.id', $videoId);
        $this->getJson($playlistPath)->assertOk()->assertJsonPath('data.videos.0.id', $videoId);

        $this->asUser($owner)->patchJson('/api/v1/videos/' . $videoId, ['visibility' => 'private'])->assertOk();
        $this->asUser($fan)->getJson($artistPath)->assertOk()->assertJsonPath('data.featured_release', null);
        $this->getJson($playlistPath)->assertOk()->assertJsonPath('data.videos_count', 0)->assertJsonPath('data.cover_url', null);
        $this->asUser($owner)->getJson('/api/v1/me/artist/analytics')->assertOk()->assertJsonPath('data.totals.videos', 1);
        $this->patchJson('/api/v1/videos/' . $videoId, ['visibility' => 'public'])->assertOk();
        $this->deleteJson('/api/v1/videos/' . $videoId)->assertOk();
        $this->asUser($fan)->getJson($playlistPath)->assertOk()->assertJsonPath('data.videos', []);
        (new DeleteVideo($videoId))->handle();
        $this->assertDatabaseCount('artist_playlist_items', 0);
        $this->assertDatabaseCount('audios', 0);
        $this->assertSame(null, Artist::findOrFail($artistId)->featured_video_id);
        $this->assertFalse(storage('public')->exists($this->mediaKey($video->storage_path)));
        $this->assertFalse(storage('public')->exists($this->mediaKey($video->thumbnail_url)));
        $this->asUser($owner)->getJson('/api/v1/me/artist/analytics')->assertOk()
            ->assertJsonPath('data.totals.videos', 0)->assertJsonPath('data.totals.views', 0)
            ->assertJsonPath('data.totals.followers', 1);
        $this->withToken($reviewer)->getJson('/api/v1/admin/reports/' . $report)->assertOk()
            ->assertJsonPath('data.evidence.caption', 'First release');
    }

    private function staffToken(string $name): string
    {
        $user = $this->makeUser($name);
        $secret = Totp::secret();
        AccountSecurity::enrollStaff($user, 'moderator', $secret, Totp::code($secret, intdiv(time(), 30) - 1));
        $token = $this->token($user);
        $this->withToken($token)->postJson('/api/v1/auth/reauthenticate', [
            'purpose' => 'staff', 'password' => 'password123', 'mfa_code' => Totp::code($secret, intdiv(time(), 30)),
        ])->assertOk();

        return $token;
    }
}
