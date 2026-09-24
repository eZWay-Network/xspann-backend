<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SocialApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_like_video_once_and_unlike_it(): void
    {
        $user = User::factory()->create();
        $video = Video::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/videos/{$video->id}/like")
            ->assertCreated()
            ->assertJsonPath('data.likes_count', 1);

        $this->postJson("/api/v1/videos/{$video->id}/like")
            ->assertOk()
            ->assertJsonPath('data.likes_count', 1);

        $this->getJson('/api/v1/me/liked-videos')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/videos/{$video->id}/like")
            ->assertOk()
            ->assertJsonPath('data.likes_count', 0);
    }

    public function test_user_can_comment_and_delete_own_comment(): void
    {
        $user = User::factory()->create();
        $video = Video::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/v1/videos/{$video->id}/comments", [
            'body' => 'Love this one.',
        ])->assertCreated()
            ->assertJsonPath('data.body', 'Love this one.');

        $this->assertSame(1, $video->fresh()->comments_count);

        $commentId = $response->json('data.id');

        $this->deleteJson("/api/v1/comments/{$commentId}")
            ->assertOk();

        $this->assertSame(0, $video->fresh()->comments_count);
    }

    public function test_user_can_save_video_once_and_unsave_it(): void
    {
        $user = User::factory()->create();
        $video = Video::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/videos/{$video->id}/save")
            ->assertCreated()
            ->assertJsonPath('data.saves_count', 1);

        $this->postJson("/api/v1/videos/{$video->id}/save")
            ->assertOk()
            ->assertJsonPath('data.saves_count', 1);

        $this->getJson('/api/v1/me/saved-videos')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/videos/{$video->id}/save")
            ->assertOk()
            ->assertJsonPath('data.saves_count', 0);
    }

    public function test_user_and_anonymous_visitor_can_share_public_video(): void
    {
        $video = Video::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/v1/videos/{$video->id}/share", ['channel' => 'copy_link'])
            ->assertCreated()
            ->assertJsonPath('data.video_id', $video->id)
            ->assertJsonPath('data.shares_count', 1);

        auth()->forgetGuards();

        $this->postJson("/api/v1/videos/{$video->id}/share", ['channel' => 'native_share'])
            ->assertCreated()
            ->assertJsonPath('data.shares_count', 2);
    }

    public function test_user_can_follow_another_user_but_not_himself(): void
    {
        $user = User::factory()->create();
        $creator = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/users/{$creator->id}/follow")
            ->assertCreated()
            ->assertJsonPath('data.following', true);

        $this->postJson("/api/v1/users/{$user->id}/follow")
            ->assertUnprocessable();

        $this->deleteJson("/api/v1/users/{$creator->id}/follow")
            ->assertOk()
            ->assertJsonPath('data.following', false);
    }
}
