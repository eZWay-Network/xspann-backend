<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiEndpointCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_read_endpoints_return_successful_json_responses(): void
    {
        $creator = User::factory()->create(['username' => 'creator_one']);
        $follower = User::factory()->create();
        $following = User::factory()->create();
        $creator->followers()->attach($follower->id);
        $creator->following()->attach($following->id);

        $video = Video::factory()->for($creator)->create();
        $comment = $video->comments()->create([
            'user_id' => $follower->id,
            'body' => 'Sharp clip.',
        ]);
        $video->update(['comments_count' => 1]);

        $this->getJson('/api/v1/feed')->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->getJson('/api/v1/videos')->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->getJson("/api/v1/videos/{$video->id}")->assertOk()->assertJsonPath('data.id', $video->id);
        $this->getJson('/api/v1/users/suggestions')->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->getJson("/api/v1/users/{$creator->username}")->assertOk()->assertJsonPath('data.username', $creator->username);
        $this->getJson("/api/v1/users/{$creator->username}/videos")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/users/{$creator->username}/followers")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/users/{$creator->username}/following")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/videos/{$video->id}/comments")->assertOk()->assertJsonPath('data.0.id', $comment->id);
    }

    public function test_authenticated_endpoints_return_successful_json_responses(): void
    {
        Queue::fake();
        Storage::fake('public');
        config(['filesystems.video_disk' => 'public']);

        $user = User::factory()->create(['username' => 'viewer_one']);
        $creator = User::factory()->create();
        $video = Video::factory()->for($creator)->create();
        $ownVideo = Video::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.username', 'viewer_one');
        $this->putJson('/api/v1/auth/profile', ['bio' => 'Updated bio'])->assertOk()->assertJsonPath('data.bio', 'Updated bio');
        $this->getJson('/api/v1/feed/following')->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->postJson('/api/v1/uploads/videos/signed-url', [
            'filename' => 'clip.mp4',
            'content_type' => 'video/mp4',
        ])->assertOk()->assertJsonPath('data.upload_method', 'multipart');
        $this->postJson('/api/v1/uploads/videos/local', [
            'file' => UploadedFile::fake()->create('clip.mp4', 64, 'video/mp4'),
        ])->assertCreated()->assertJsonPath('data.upload_method', 'multipart');
        $this->postJson('/api/v1/videos', [
            'storage_path' => 'videos/viewer/clip.mp4',
            'caption' => 'Ready for processing.',
        ])->assertCreated()->assertJsonPath('data.status', Video::STATUS_PROCESSING);
        $this->postJson("/api/v1/videos/{$video->id}/like")->assertCreated()->assertJsonPath('data.liked', true);
        $this->getJson('/api/v1/me/liked-videos')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/videos/{$video->id}/like")->assertOk()->assertJsonPath('data.liked', false);
        $commentId = $this->postJson("/api/v1/videos/{$video->id}/comments", ['body' => 'Nice one'])
            ->assertCreated()
            ->json('data.id');
        $this->deleteJson("/api/v1/comments/{$commentId}")->assertOk()->assertJsonPath('data.message', 'Comment deleted');
        $this->postJson("/api/v1/videos/{$video->id}/save")->assertCreated()->assertJsonPath('data.saved', true);
        $this->getJson('/api/v1/me/saved-videos')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/videos/{$video->id}/save")->assertOk()->assertJsonPath('data.saved', false);
        $this->postJson("/api/v1/users/{$creator->id}/follow")->assertCreated()->assertJsonPath('data.following', true);
        $this->deleteJson("/api/v1/users/{$creator->id}/follow")->assertOk()->assertJsonPath('data.following', false);
        $this->deleteJson("/api/v1/videos/{$ownVideo->id}")->assertOk()->assertJsonPath('data.message', 'Video deleted');
        $this->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('data.message', 'Logged out');
    }

    public function test_protected_endpoints_require_authentication(): void
    {
        $user = User::factory()->create();
        $video = Video::factory()->create();
        $comment = Comment::create([
            'user_id' => $user->id,
            'video_id' => $video->id,
            'body' => 'Auth check.',
        ]);

        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->putJson('/api/v1/auth/profile', [])->assertUnauthorized();
        $this->getJson('/api/v1/feed/following')->assertUnauthorized();
        $this->postJson('/api/v1/uploads/videos/signed-url', [])->assertUnauthorized();
        $this->postJson('/api/v1/uploads/videos/local', [])->assertUnauthorized();
        $this->postJson('/api/v1/videos', [])->assertUnauthorized();
        $this->deleteJson("/api/v1/videos/{$video->id}")->assertUnauthorized();
        $this->postJson("/api/v1/videos/{$video->id}/like")->assertUnauthorized();
        $this->deleteJson("/api/v1/videos/{$video->id}/like")->assertUnauthorized();
        $this->getJson('/api/v1/me/liked-videos')->assertUnauthorized();
        $this->postJson("/api/v1/videos/{$video->id}/comments", [])->assertUnauthorized();
        $this->deleteJson("/api/v1/comments/{$comment->id}")->assertUnauthorized();
        $this->getJson('/api/v1/me/saved-videos')->assertUnauthorized();
        $this->postJson("/api/v1/videos/{$video->id}/save")->assertUnauthorized();
        $this->deleteJson("/api/v1/videos/{$video->id}/save")->assertUnauthorized();
        $this->postJson("/api/v1/users/{$user->id}/follow")->assertUnauthorized();
        $this->deleteJson("/api/v1/users/{$user->id}/follow")->assertUnauthorized();
    }
}
