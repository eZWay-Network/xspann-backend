<?php

namespace Tests\Feature;

use App\Models\{Video, Follow};
use Tests\TestCase;

class VideoApiTest extends TestCase
{
    public function testPublicFeedVisibilityOptionalViewerAndPagination(): void
    {
        $owner = $this->makeUser();
        $viewer = $this->makeUser('bob');
        $public = $this->makeVideo($owner);
        $followers = $this->makeVideo($owner, ['visibility' => 'followers']);
        $private = $this->makeVideo($owner, ['visibility' => 'private']);
        $processing = $this->makeVideo($owner, ['status' => 'processing']);
        $this->makeVideo($owner, ['status' => 'deleted']);
        $this->getJson('/api/v1/feed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.tags.0', '#world');
        $this->getJson('/api/v1/videos/' . $followers->id)->assertStatus(404);
        $this->getJson('/api/v1/videos/' . $processing->id)->assertStatus(404);
        Follow::create(['follower_id' => $viewer->id, 'following_id' => $owner->id]);
        $this->asUser($viewer);
        $this->getJson('/api/v1/feed?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.viewer.following', true);
        $this->getJson('/api/v1/feed/following')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/users/alice/videos')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/videos/' . $private->id)->assertStatus(404);
        $this->asUser($owner);
        $this->getJson('/api/v1/videos/' . $processing->id)->assertOk();
        $this->getJson('/api/v1/me/videos')->assertOk()->assertJsonCount(4, 'data');
    }

    public function testCreatePersistsEditorMetadataAndDispatchesQueue(): void
    {
        $user = $this->makeUser();
        $this->asUser($user);
        $data = [
            'storage_path' => "videos/$user->id/example.mp4",
            'caption' => '#test',
            'visibility' => 'followers',
            'trim_start' => 1.5,
            'trim_end' => 9.5,
            'cut_points' => [2, 4],
            'cover_time' => 3,
            'crop_mode' => 'fill',
            'original_audio_muted' => true,
            'filter_settings' => ['brightness' => 110],
            'effect_settings' => ['arFace' => 'Smile'],
            'sound_provider' => 'local',
            'sound_name' => 'My audio'
        ];
        $response = $this->postJson('/api/v1/videos', $data)->assertStatus(201)->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.edit.trim_start', 1.5)->assertJsonPath('data.edit.effect_settings.arFace', 'Smile');
        $this->assertDatabaseHas('videos', ['id' => $response->json('data.id'), 'user_id' => $user->id, 'visibility' => 'followers']);
        $queue = app(\Spark\Queue\Queue::class)->getConnection();
        $this->assertSame(1, (int) $queue->query("SELECT COUNT(*) FROM jobs WHERE queue = 'default'")->fetchColumn());
    }

    public function testEditorValidationAndOwnership(): void
    {
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner);
        $this->asUser($this->makeUser('bob'));
        $this->patchJson('/api/v1/videos/' . $video->id, ['caption' => 'attack'])->assertStatus(403);
        $this->deleteJson('/api/v1/videos/' . $video->id)->assertStatus(403);
        $this->asUser($owner);
        $this->postJson('/api/v1/videos', ['storage_path' => 'videos/999/file.mp4'])->assertStatus(422);
        $this->patchJson('/api/v1/videos/' . $video->id, ['trim_start' => 4, 'trim_end' => 2, 'cut_points' => [-1], 'filter_settings' => ['brightness' => 999]])
            ->assertStatus(422)->assertJsonValidationErrors(['cut_points.0', 'filter_settings.brightness']);
        $this->patchJson('/api/v1/videos/' . $video->id, ['trim_start' => 4, 'trim_end' => 2])
            ->assertStatus(422)->assertJsonValidationErrors(['trim_end']);
        $this->patchJson('/api/v1/videos/' . $video->id, ['pinned' => true, 'caption' => 'Updated', 'status' => 'deleted'])->assertOk()->assertJsonPath('data.caption', 'Updated')->assertJsonPath('data.status', 'published');
        $this->assertTrue(Video::find($video->id)->pinned_at !== null);
        $this->deleteJson('/api/v1/videos/' . $video->id)->assertOk();
        $this->getJson('/api/v1/videos/' . $video->id)->assertStatus(404);
        $this->assertDatabaseHas('videos', ['id' => $video->id, 'status' => 'deleted']);
    }

    public function testUserSuggestionsProfilesAndFollowLists(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $carol = $this->makeUser('carol');
        $this->makeVideo($alice, ['likes_count' => 3]);
        Follow::create(['follower_id' => $bob->id, 'following_id' => $alice->id]);
        $this->getJson('/api/v1/users/suggestions')->assertOk()->assertJsonPath('data.0.username', 'alice');
        $this->getJson('/api/v1/users/alice')->assertOk()->assertJsonPath('data.likes_count', 3)->assertJsonPath('data.followers_count', 1);
        $this->getJson('/api/v1/users/alice/followers')->assertOk()->assertJsonPath('data.0.username', 'bob');
        $this->getJson('/api/v1/users/bob/following')->assertOk()->assertJsonPath('data.0.username', 'alice');
        $this->asUser($bob);
        $this->getJson('/api/v1/users/suggestions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.username', 'carol');
        $this->getJson('/api/v1/users/alice')->assertJsonPath('data.following', true);
    }

    public function testAllProtectedRoutesRejectGuests(): void
    {
        $routes = [
            ['POST', '/auth/logout'],
            ['GET', '/auth/me'],
            ['PUT', '/auth/profile'],
            ['PUT', '/auth/password'],
            ['POST', '/auth/token/refresh'],
            ['GET', '/feed/following'],
            ['POST', '/uploads/avatar'],
            ['POST', '/uploads/videos/signed-url'],
            ['POST', '/uploads/videos/local'],
            ['POST', '/uploads/videos/chunk'],
            ['POST', '/uploads/videos/complete'],
            ['POST', '/uploads/sounds/local'],
            ['GET', '/me/videos'],
            ['POST', '/videos'],
            ['PATCH', '/videos/1'],
            ['DELETE', '/videos/1'],
            ['GET', '/me/liked-videos'],
            ['POST', '/videos/1/like'],
            ['DELETE', '/videos/1/like'],
            ['POST', '/videos/1/comments'],
            ['DELETE', '/comments/1'],
            ['GET', '/me/saved-videos'],
            ['POST', '/videos/1/save'],
            ['DELETE', '/videos/1/save'],
            ['POST', '/users/1/follow'],
            ['DELETE', '/users/1/follow'],
            ['GET', '/me/blocks'],
            ['POST', '/users/1/block'],
            ['DELETE', '/users/1/block'],
            ['POST', '/comments/1/reaction'],
            ['DELETE', '/comments/1/reaction'],
            ['POST', '/reports'],
            ['GET', '/notifications'],
            ['PATCH', '/notifications/read-all'],
            ['DELETE', '/notifications/clear-all'],
            ['PATCH', '/notifications/1/read'],
        ];
        foreach ($routes as [$method, $path]) {
            $this->request($method, "/api/v1$path", json: true)->assertStatus(401);
        }
    }
}
