<?php

namespace Tests\Feature;

use App\Models\{Video, Comment};
use Tests\TestCase;

class SocialApiTest extends TestCase
{
    public function testLikesAndSavesAreIdempotentAndViewerStateMatches(): void
    {
        $user = $this->makeUser();
        $video = $this->makeVideo($user);
        $this->asUser($user);
        foreach (['like' => 'likes', 'save' => 'saves'] as $action => $table) {
            $this->postJson("/api/v1/videos/$video->id/$action")->assertStatus(201)->assertJsonPath('data.created', true)->assertJsonPath("data.{$table}_count", 1);
            $this->postJson("/api/v1/videos/$video->id/$action")->assertOk()->assertJsonPath('data.created', false);
            $this->assertDatabaseCount($table, 1);
        }
        $this->getJson('/api/v1/me/liked-videos')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/me/saved-videos')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/feed')->assertJsonPath('data.0.viewer.liked', true)->assertJsonPath('data.0.viewer.saved', true);
        foreach (['like' => 'likes', 'save' => 'saves'] as $action => $table) {
            $this->deleteJson("/api/v1/videos/$video->id/$action")->assertOk()->assertJsonPath("data.{$table}_count", 0);
            $this->deleteJson("/api/v1/videos/$video->id/$action")->assertOk()->assertJsonPath("data.{$table}_count", 0);
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function testCommentsOwnershipReplyBoundariesAndCascadeCounters(): void
    {
        $owner = $this->makeUser();
        $bob = $this->makeUser('bob');
        $video = $this->makeVideo($owner);
        $other = $this->makeVideo($owner);
        $this->asUser($owner);
        $id = $this->postJson("/api/v1/videos/$video->id/comments", ['body' => 'Root'])->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/videos/$video->id/comments", ['body' => 'Reply', 'parent_id' => $id])->assertStatus(201);
        $this->postJson("/api/v1/videos/$other->id/comments", ['body' => 'Wrong video', 'parent_id' => $id])->assertStatus(422);
        $this->postJson("/api/v1/videos/$video->id/comments", ['body' => ''])->assertStatus(422);
        $this->getJson("/api/v1/videos/$video->id/comments")->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(2, Video::find($video->id)->comments_count);
        $this->asUser($bob)->deleteJson("/api/v1/comments/$id")->assertStatus(403);
        $this->asUser($owner)->deleteJson("/api/v1/comments/$id")->assertOk();
        $this->assertDatabaseCount('comments', 0);
        $this->assertSame(0, Video::find($video->id)->comments_count);
    }

    public function testSharesViewsAndPrivacy(): void
    {
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner);
        $private = $this->makeVideo($owner, ['visibility' => 'private']);
        $this->postJson("/api/v1/videos/$video->id/share", ['channel' => 'whatsapp'])->assertStatus(201)->assertJsonPath('data.shares_count', 1);
        $this->postJson("/api/v1/videos/$video->id/share", ['channel' => 'bad'])->assertStatus(422);
        $this->postJson("/api/v1/videos/$video->id/view")->assertStatus(201)->assertJsonPath('data.views_count', 1);
        $this->postJson("/api/v1/videos/$video->id/view")->assertOk()->assertJsonPath('data.views_count', 1);
        $this->postJson("/api/v1/videos/$private->id/share")->assertStatus(404);
        $this->asUser($this->makeUser('bob'));
        foreach (['like', 'save', 'view', 'share', 'comments'] as $action) {
            $this->postJson("/api/v1/videos/$private->id/$action", ['body' => 'Hidden'])->assertStatus(404);
        }
        $this->postJson("/api/v1/videos/$video->id/view")->assertStatus(201)->assertJsonPath('data.views_count', 2);
        $this->assertDatabaseCount('video_views', 2);
    }

    public function testFollowIsIdempotentAndCannotFollowSelf(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $this->asUser($alice);
        $this->postJson("/api/v1/users/$alice->id/follow")->assertStatus(422);
        $this->postJson("/api/v1/users/$bob->id/follow")->assertStatus(201)->assertJsonPath('data.followers_count', 1);
        $this->postJson("/api/v1/users/$bob->id/follow")->assertStatus(201)->assertJsonPath('data.followers_count', 1);
        $this->assertDatabaseCount('follows', 1);
        $this->deleteJson("/api/v1/users/$bob->id/follow")->assertOk()->assertJsonPath('data.followers_count', 0);
        $this->deleteJson("/api/v1/users/$bob->id/follow")->assertOk();
        $this->assertDatabaseCount('follows', 0);
    }
    public function testViewWindowExpiresAndNullShareChannelUsesDefault(): void
    {
        $video = $this->makeVideo($this->makeUser());
        $this->postJson("/api/v1/videos/$video->id/view")->assertStatus(201);
        query('video_views')->where('video_id', $video->id)->update(['created_at' => now()->subHours(7)]);
        $this->postJson("/api/v1/videos/$video->id/view")->assertStatus(201)->assertJsonPath('data.views_count', 2);
        $this->postJson("/api/v1/videos/$video->id/share", ['channel' => null])->assertStatus(201);
        $this->assertDatabaseHas('shares', ['video_id' => $video->id, 'channel' => 'copy_link']);
    }

}
