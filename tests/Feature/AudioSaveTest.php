<?php

namespace Tests\Feature;

use App\Models\{Audio, AudioSave, Block};
use Tests\TestCase;

class AudioSaveTest extends TestCase
{
    private function audio(int $userId, array $attributes = []): Audio
    {
        return Audio::create([
            'user_id' => $userId,
            'title' => 'Studio sound',
            'storage_path' => "sounds/$userId/sound.m4a",
            'status' => 'ready',
            ...$attributes,
        ]);
    }

    public function testSavingIsIdempotentPrivateAndReflectedInResources(): void
    {
        $owner = $this->makeUser();
        $viewer = $this->makeUser('bob');
        $audio = $this->audio($owner->id);
        $route = "/api/v1/audios/$audio->id/save";
        $this->postJson($route)->assertStatus(401);
        $this->deleteJson($route)->assertStatus(401);
        $this->getJson('/api/v1/me/saved-audios')->assertStatus(401);
        $this->getJson('/api/v1/audios')->assertJsonPath('data.0.viewer.saved', false);
        $this->asUser($viewer)->postJson($route)->assertStatus(201)->assertJsonPath('data.created', true);
        $this->postJson($route)->assertOk()->assertJsonPath('data.created', false);
        $this->assertSame(1, AudioSave::count());
        $this->getJson('/api/v1/me/saved-audios')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $audio->id)->assertJsonPath('data.0.viewer.saved', true);
        $this->getJson('/api/v1/audios/' . $audio->id)->assertJsonPath('data.viewer.saved', true);
        $this->makeVideo($owner, ['audio_id' => $audio->id]);
        $this->getJson('/api/v1/feed')->assertJsonPath('data.0.audio.viewer.saved', true);
        $this->asUser($owner)->getJson('/api/v1/me/saved-audios')->assertJsonCount(0, 'data');
        $this->deleteJson($route)->assertOk();
        $this->assertSame(1, AudioSave::count());
        $this->asUser($viewer)->deleteJson($route)->assertOk()->assertJsonPath('data.saved', false);
        $this->deleteJson($route)->assertOk();
        $this->assertSame(0, AudioSave::count());
        $this->getJson('/api/v1/audios/' . $audio->id)->assertJsonPath('data.viewer.saved', false);
    }

    public function testSavedSoundsRespectSourcePrivacyAndCanStillBeRemoved(): void
    {
        $owner = $this->makeUser();
        $viewer = $this->makeUser('bob');
        $video = $this->makeVideo($owner);
        $audio = $this->audio($owner->id, ['origin' => 'original', 'source_video_id' => $video->id]);
        $route = "/api/v1/audios/$audio->id/save";
        $this->asUser($viewer)->postJson($route)->assertStatus(201);
        $video->update(['visibility' => 'private']);
        $this->getJson('/api/v1/me/saved-audios')->assertJsonCount(0, 'data');
        $this->postJson($route)->assertStatus(404);
        $video->update(['visibility' => 'public']);
        $this->getJson('/api/v1/me/saved-audios')->assertJsonCount(1, 'data');
        Block::create(['blocker_id' => $owner->id, 'blocked_id' => $viewer->id]);
        $this->getJson('/api/v1/me/saved-audios')->assertJsonCount(0, 'data');
        $this->postJson($route)->assertStatus(404);
        $this->deleteJson($route)->assertOk();
        $this->assertSame(0, AudioSave::count());
        $pending = $this->audio($viewer->id, ['status' => 'processing']);
        $this->postJson("/api/v1/audios/$pending->id/save")->assertStatus(404);
        $this->postJson('/api/v1/audios/99999/save')->assertStatus(404);
    }

    public function testPaginationQueryCountsAndCascadeDeletion(): void
    {
        $viewer = $this->makeUser();
        $this->asUser($viewer);
        $ids = [];
        for ($index = 0; $index < 5; $index++) {
            $creator = $this->makeUser('creator' . $index);
            $audio = $this->audio($creator->id);
            $ids[] = $audio->id;
            $this->postJson("/api/v1/audios/$audio->id/save")->assertStatus(201);
        }
        $queries = 0;
        event()->addListener('app:db.queryExecuted', function () use (&$queries): void {
            $queries++;
        });
        $this->getJson('/api/v1/me/saved-audios?limit=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 5)
            ->assertJsonPath('data.0.id', $ids[4]);
        $small = $queries;
        $queries = 0;
        $this->getJson('/api/v1/me/saved-audios?limit=5')->assertJsonCount(5, 'data');
        $this->assertSame($small, $queries);
        $this->getJson('/api/v1/me/saved-audios?limit=1&page=2')->assertJsonPath('data.0.id', $ids[3]);
        Audio::find($ids[0])->delete();
        $this->assertSame(4, AudioSave::count());
        $viewer->delete();
        $this->assertSame(0, AudioSave::count());
    }
}
