<?php

namespace Tests\Feature;

use App\Jobs\{DeleteAudio, ProcessVideo};
use App\Models\{Audio, Video};
use App\Services\MediaProcessor;
use Tests\TestCase;

class AudioRightsTest extends TestCase
{
    public function testRemovedProviderRoutesAndFieldsAreRejectedIncludingExplicitNull(): void
    {
        foreach (['/sounds/jamendo', '/sounds/jamendo/123', '/sounds/jamendo/123/videos'] as $path) {
            $this->getJson('/api/v1' . $path)->assertStatus(404);
        }
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner);
        storage('public')->put('videos/1/input.mp4', 'video');
        $this->asUser($owner);
        foreach (['sound_name', 'sound_artist', 'sound_provider', 'sound_external_id', 'sound_preview_url', 'scheduled_at'] as $field) {
            $this->postJson('/api/v1/videos', [...$this->videoRights(), 'storage_path' => 'videos/1/input.mp4', $field => null])->assertStatus(422)->assertJsonValidationErrors([$field]);
            $this->patchJson('/api/v1/videos/' . $video->id, [$field => null])->assertStatus(422)->assertJsonValidationErrors([$field]);
        }
        $this->assertDatabaseCount('videos', 1);
    }

    public function testRightsAndExplicitReusePermissionAreRequiredForVideoAndAudio(): void
    {
        $owner = $this->makeUser();
        $this->asUser($owner);
        storage('public')->put('videos/1/input.mp4', 'video');
        storage('public')->put('sounds/1/input.m4a', 'audio');
        $base = ['storage_path' => 'videos/1/input.mp4'];
        $this->postJson('/api/v1/videos', $base)->assertStatus(422)->assertJsonValidationErrors(['rights_confirmed', 'rights_version', 'reuse_content']);
        $this->postJson('/api/v1/videos', [...$base, ...$this->videoRights(), 'rights_confirmed' => false])->assertStatus(422);
        $this->postJson('/api/v1/videos', [...$base, ...$this->videoRights(), 'rights_version' => 'old'])->assertStatus(422);
        $id = $this->postJson('/api/v1/videos', [...$base, ...$this->videoRights(), 'reuse_content' => false])->assertStatus(201)->assertJsonPath('data.reuse_content', false)->json('data.id');
        $this->assertTrue(Video::find($id)->rights_confirmed_at !== null);
        $base = ['storage_path' => 'sounds/1/input.m4a', 'title' => 'My music'];
        $this->postJson('/api/v1/audios', $base)->assertStatus(422)->assertJsonValidationErrors(['rights_confirmed', 'rights_version', 'reuse_allowed']);
        $audio = $this->postJson('/api/v1/audios', [...$base, ...$this->audioRights(), 'reuse_allowed' => false])->assertStatus(201)->json('data.id');
        $this->assertFalse(Audio::find($audio)->reuse_allowed);
        $this->assertTrue(Audio::find($audio)->rights_confirmed_at !== null);
    }

    public function testAudioOwnershipPermissionWithdrawalAndDeletionPreserveRenderedClips(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser('other');
        $audio = Audio::create(['user_id' => $owner->id, 'title' => 'Music', 'storage_path' => 'sounds/1/music.m4a', 'status' => 'ready', 'reuse_allowed' => true]);
        storage('public')->put('sounds/1/music.m4a', 'music');
        $clip = $this->makeVideo($other, ['audio_id' => $audio->id]);
        storage('public')->put($this->mediaKey($clip->storage_path), 'rendered-clip');
        $this->asUser($other)->patchJson('/api/v1/audios/' . $audio->id, ['title' => 'Forged'])->assertStatus(403);
        $this->deleteJson('/api/v1/audios/' . $audio->id)->assertStatus(403);
        $this->asUser($owner)->patchJson('/api/v1/audios/' . $audio->id, ['reuse_allowed' => false])->assertOk();
        $this->flushHeaders()->getJson('/api/v1/audios/' . $audio->id)->assertStatus(404);
        $this->getJson('/api/v1/videos/' . $clip->id)->assertOk()->assertJsonPath('data.audio', null);
        $this->asUser($owner)->getJson('/api/v1/audios/' . $audio->id)->assertOk();
        $this->deleteJson('/api/v1/audios/' . $audio->id)->assertStatus(202);
        $this->getJson('/api/v1/audios/' . $audio->id)->assertStatus(404);
        (new DeleteAudio($audio->id))->handle();
        (new DeleteAudio($audio->id))->handle();
        $this->assertFalse(storage('public')->exists('sounds/1/music.m4a'));
        $this->assertSame(null, $clip->refresh()->audio_id);
        $this->assertTrue(storage('public')->exists($this->mediaKey($clip->storage_path)));
    }

    public function testReuseWithdrawalDuringEncodingSkipsExtractionAndCleansUnusedOutput(): void
    {
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner, ['status' => 'processing']);
        $this->app->instance(MediaProcessor::class, new class extends MediaProcessor {
            public function video(Video $video): array
            {
                storage('public')->put('videos/1/encoded.mp4', 'video');
                storage('public')->put('sounds/1/extracted.m4a', 'audio');
                $video->update(['reuse_content' => false]);

                return ['storage_path' => 'public:videos/1/encoded.mp4', 'audio_path' => 'public:sounds/1/extracted.m4a', 'duration' => 1];
            }
        });
        (new ProcessVideo($video->id))->handle();
        $this->assertSame('published', $video->refresh()->status);
        $this->assertSame(null, $video->audio_id);
        $this->assertFalse(storage('public')->exists('sounds/1/extracted.m4a'));
    }

    public function testModerationDuringEncodingCannotPublishAndPermissionChangesStaySynchronized(): void
    {
        $owner = $this->makeUser();
        $source = $this->makeVideo($owner);
        $audio = Audio::create(['user_id' => $owner->id, 'title' => 'Original', 'origin' => 'original', 'source_video_id' => $source->id, 'storage_path' => 'sounds/1/a.m4a', 'status' => 'ready', 'reuse_allowed' => true]);
        $this->asUser($owner)->patchJson('/api/v1/videos/' . $source->id, ['reuse_content' => false])->assertOk();
        $this->assertFalse($audio->refresh()->reuse_allowed);
        $this->patchJson('/api/v1/audios/' . $audio->id, ['reuse_allowed' => true])->assertOk();
        $this->assertTrue($source->refresh()->reuse_content);
        $pending = $this->makeVideo($owner, ['status' => 'processing']);
        $this->app->instance(MediaProcessor::class, new class extends MediaProcessor {
            public function video(Video $video): array
            {
                $video->update(['moderation_status' => 'restricted']);
                storage('public')->put('videos/1/encoded.mp4', 'video');

                return ['storage_path' => 'public:videos/1/encoded.mp4', 'duration' => 1];
            }
        });
        (new ProcessVideo($pending->id))->handle();
        $this->assertSame('processing', $pending->refresh()->status);
        $this->assertFalse(storage('public')->exists('videos/1/encoded.mp4'));
        $this->getJson('/api/v1/videos/' . $pending->id)->assertStatus(404);
    }
}
