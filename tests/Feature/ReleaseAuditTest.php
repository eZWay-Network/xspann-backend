<?php

namespace Tests\Feature;

use App\Jobs\DeleteAudio;
use App\Models\{Audio, User, Video};
use Tests\Support\ArtistFixtures;
use Tests\TestCase;

class ReleaseAuditTest extends TestCase
{
    use ArtistFixtures;

    private function original(User $owner): array
    {
        $this->makeArtist($owner);
        storage('local')->put("videos/$owner->id/audit.mp4", 'private rendered video');
        storage('local')->put("sounds/$owner->id/audit.m4a", 'private original audio');
        $video = $this->makeVideo($owner, [
            'storage_path' => "local:videos/$owner->id/audit.mp4", 'status' => 'draft',
            'processing_state' => 'ready', 'publication_state' => 'draft', 'publication_intent' => 'draft',
        ]);
        $audio = Audio::create([
            'user_id' => $owner->id, 'source_video_id' => $video->id, 'origin' => 'original', 'title' => 'Original',
            'storage_path' => "local:sounds/$owner->id/audit.m4a", 'status' => 'ready', 'reuse_allowed' => true,
            'rights_version' => config('policies.rights_version'), 'rights_confirmed_at' => now(),
        ]);
        $video->update(['audio_id' => $audio->id]);

        return [$video, $audio];
    }

    public function testOriginalSoundDetailUsesOwnerPreviewAndHonorsSourceModeration(): void
    {
        $owner = $this->makeUser();
        [$video, $audio] = $this->original($owner);
        $expected = (string) url("api/v1/me/videos/$video->id/preview/original_audio");
        $this->asUser($owner)->getJson("/api/v1/audios/$audio->id")->assertOk()->assertJsonPath('data.audio_url', $expected);
        $video->update(['moderation_status' => 'restricted']);
        $this->getJson("/api/v1/audios/$audio->id")->assertOk()->assertJsonPath('data.audio_url', null);
        $this->asUser($this->makeUser('other'))->getJson("/api/v1/audios/$audio->id")->assertStatus(404);
    }

    public function testPrivateS3OriginalAudioUsesTheShortLivedAuthorizedPreview(): void
    {
        $owner = $this->makeUser();
        [$video, $audio] = $this->original($owner);
        $this->useS3(['temporary_urls' => true]);
        $audio->update(['storage_path' => "s3:sounds/$owner->id/private.m4a"]);
        $path = "/api/v1/me/videos/$video->id/preview/original_audio";
        $this->asUser($owner)->getJson("/api/v1/audios/$audio->id")->assertOk()->assertJsonPath('data.audio_url', (string) url(ltrim($path, '/')));
        $location = $this->get($path)->assertStatus(302)->assertHeader('Cache-Control', 'private, no-store')->response->getHeaders()['Location'];
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('60', $query['X-Amz-Expires']);
        $video->update(['moderation_status' => 'restricted']);
        $this->getJson("/api/v1/audios/$audio->id")->assertOk()->assertJsonPath('data.audio_url', null);
        $this->get($path)->assertStatus(404);
    }

    public function testSoundReuseEditsRespectReleaseVersionsAndScheduledEditRestrictions(): void
    {
        $owner = $this->makeUser();
        [$video, $audio] = $this->original($owner);
        $this->asUser($owner)->patchJson("/api/v1/audios/$audio->id", ['reuse_allowed' => false])->assertOk();
        $this->assertSame(2, $video->refresh()->version);
        $this->patchJson("/api/v1/me/artist/releases/$video->id", ['version' => 1, 'reuse_content' => true])->assertStatus(409);
        $video->update(['publication_state' => 'scheduled', 'status' => 'scheduled']);
        $this->patchJson("/api/v1/audios/$audio->id", ['reuse_allowed' => true])->assertStatus(409);
        $this->assertFalse($video->refresh()->reuse_content);
    }

    public function testRemovingOwnExtractedSoundDoesNotInvalidateTheAuthoredReadyVideo(): void
    {
        $owner = $this->makeUser();
        [$video, $audio] = $this->original($owner);
        $this->asUser($owner)->deleteJson("/api/v1/audios/$audio->id")->assertStatus(202);
        (new DeleteAudio($audio->id))->handle();
        $this->assertSame('ready', $video->refresh()->processing_state);
        $this->assertSame('draft', $video->status);
        $this->assertSame(null, $video->audio_id);
        $this->assertSame(2, $video->version);
        $this->postJson("/api/v1/me/artist/releases/$video->id/publish", ['version' => 2])->assertOk();
    }

    public function testLegacyImmediateEditsInvalidateOlderReleaseVersions(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $video = $this->makeVideo($owner, [
            'status' => 'processing', 'processing_state' => 'processing',
            'publication_state' => 'draft', 'publication_intent' => 'immediate',
        ]);
        $this->asUser($owner)->patchJson("/api/v1/videos/$video->id", ['caption' => 'New caption'])->assertOk();
        $this->assertSame(2, $video->refresh()->version);
        $this->patchJson("/api/v1/me/artist/releases/$video->id", ['version' => 1, 'caption' => 'Stale caption'])->assertStatus(409);
        $this->assertSame('New caption', $video->refresh()->caption);
    }

    public function testDeletedSoundStillInvalidatesOtherUnpublishedDerivatives(): void
    {
        $owner = $this->makeUser();
        [$source, $audio] = $this->original($owner);
        $other = $this->makeUser('other');
        $derivative = $this->makeVideo($other, [
            'audio_id' => $audio->id, 'status' => 'scheduled', 'processing_state' => 'ready',
            'publication_state' => 'scheduled', 'publication_intent' => 'draft',
            'publish_at' => '2099-01-01 00:00:00', 'publish_timezone' => 'UTC',
        ]);
        $this->asUser($owner)->deleteJson("/api/v1/audios/$audio->id")->assertStatus(202);
        (new DeleteAudio($audio->id))->handle();
        $this->assertSame('ready', $source->refresh()->processing_state);
        $this->assertSame('source_unavailable', $derivative->refresh()->status);
        $this->assertSame('draft', $derivative->publication_state);
        $this->assertSame(null, $derivative->publish_at);
        $this->assertSame(2, $derivative->version);
    }
}
