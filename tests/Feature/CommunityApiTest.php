<?php

namespace Tests\Feature;

use App\Models\{Comment, Follow, Notification};
use Tests\TestCase;

class CommunityApiTest extends TestCase
{
    public function testBlocksAreIdempotentAndHideProfilesVideosAndInteractionsInBothDirections(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $video = $this->makeVideo($bob);
        Follow::create(['follower_id' => $alice->id, 'following_id' => $bob->id]);
        Follow::create(['follower_id' => $bob->id, 'following_id' => $alice->id]);
        $this->asUser($alice);
        $this->postJson('/api/v1/users/' . $alice->id . '/block')->assertStatus(422);
        $path = '/api/v1/users/' . $bob->id . '/block';
        $this->postJson($path)->assertStatus(201)->assertJsonPath('data.blocked', true);
        $this->postJson($path)->assertStatus(201);
        $this->assertDatabaseCount('blocks', 1);
        $this->assertDatabaseCount('follows', 0);
        $this->getJson('/api/v1/me/blocks')->assertOk()->assertJsonPath('data.0.id', $bob->id);
        $this->getJson('/api/v1/feed')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/users/bob')->assertStatus(404);
        $this->getJson('/api/v1/users/bob/videos')->assertStatus(404);
        $this->getJson('/api/v1/users/suggestions')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/videos/' . $video->id)->assertStatus(404);
        $this->postJson('/api/v1/videos/' . $video->id . '/like')->assertStatus(404);
        $this->postJson('/api/v1/users/' . $bob->id . '/follow')->assertStatus(404);
        $this->asUser($bob)->getJson('/api/v1/users/alice')->assertStatus(404);
        $this->asUser($alice)->deleteJson($path)->assertOk();
        $this->deleteJson($path)->assertOk();
        $this->getJson('/api/v1/videos/' . $video->id)->assertOk();
    }

    public function testReactionsDefaultToLikeAndReplaceWithoutDuplicates(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $video = $this->makeVideo($alice);
        $comment = Comment::create(['user_id' => $alice->id, 'video_id' => $video->id, 'body' => 'Hello']);
        $path = '/api/v1/comments/' . $comment->id . '/reaction';
        $this->postJson($path)->assertStatus(401);
        $this->asUser($bob)->postJson($path)->assertOk()->assertJsonPath('data.viewer_reaction', 'like')->assertJsonPath('data.reactions.like', 1);
        $this->postJson($path)->assertOk()->assertJsonPath('data.reactions.like', 1);
        foreach (\App\Models\CommentReaction::TYPES as $type) {
            $this->postJson($path, ['reaction_type' => $type])->assertOk()
                ->assertJsonPath('data.viewer_reaction', $type)->assertJsonPath('data.reactions.' . $type, 1);
            $this->assertDatabaseCount('comments_reacts', 1);
        }
        $this->postJson($path, ['reaction_type' => 'invalid'])->assertStatus(422);
        $this->deleteJson($path)->assertOk()->assertJsonPath('data.viewer_reaction', null);
        $this->deleteJson($path)->assertOk();
        $this->assertDatabaseCount('comments_reacts', 0);
        $this->postJson('/api/v1/users/' . $alice->id . '/block')->assertStatus(201);
        $this->postJson($path)->assertStatus(404);
    }

    public function testRepliesAndReactionsCascadeWhenParentIsDeleted(): void
    {
        $alice = $this->makeUser();
        $video = $this->makeVideo($alice);
        $this->asUser($alice);
        $parent = $this->postJson('/api/v1/videos/' . $video->id . '/comments', ['body' => 'Parent'])->assertStatus(201)->json('data.id');
        $reply = $this->postJson('/api/v1/videos/' . $video->id . '/comments', ['body' => 'Reply', 'parent_id' => $parent])->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/comments/' . $reply . '/reaction')->assertOk();
        $this->getJson('/api/v1/comments/' . $parent . '/replies')->assertOk()->assertJsonPath('data.0.id', $reply);
        $this->getJson('/api/v1/videos/' . $video->id . '/comments')->assertOk()->assertJsonPath('data.0.replies_count', 1);
        $this->deleteJson('/api/v1/comments/' . $parent)->assertOk();
        $this->assertDatabaseCount('comments', 0);
        $this->assertDatabaseCount('comments_reacts', 0);
        $this->assertSame(0, $video->refresh()->comments_count);
    }

    public function testBlockedCommentAuthorsAreExcludedFromRepliesAndCounts(): void
    {
        $owner = $this->makeUser();
        $blocked = $this->makeUser('blocked');
        $video = $this->makeVideo($owner);
        $parent = Comment::create(['user_id' => $owner->id, 'video_id' => $video->id, 'body' => 'Parent']);
        Comment::create(['user_id' => $blocked->id, 'video_id' => $video->id, 'parent_id' => $parent->id, 'body' => 'Reply']);
        $this->asUser($owner)->postJson('/api/v1/users/' . $blocked->id . '/block')->assertStatus(201);
        $this->getJson('/api/v1/videos/' . $video->id . '/comments')->assertOk()->assertJsonPath('data.0.replies_count', 0);
        $this->getJson('/api/v1/comments/' . $parent->id . '/replies')->assertOk()->assertJsonCount(0, 'data');
    }

    public function testReportsValidateTheirTargetAndNotificationsAreOwnerOnly(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $video = $this->makeVideo($bob);
        $this->asUser($alice);
        $this->postJson('/api/v1/reports', ['reason' => 'Spam'])->assertStatus(422);
        $this->postJson('/api/v1/reports', ['video_id' => $video->id, 'reported_user_id' => $bob->id, 'reason' => 'Spam'])->assertStatus(422);
        $this->postJson('/api/v1/reports', ['video_id' => $video->id, 'reason' => 'Spam', 'status' => 'closed'])->assertStatus(201)->assertJsonPath('data.status', 'open');
        $this->postJson('/api/v1/reports', ['reported_user_id' => $bob->id, 'reason' => 'Spam'])->assertStatus(201);
        $own = Notification::create(['user_id' => $alice->id, 'type' => 'test', 'data' => ['message' => 'Hello']]);
        $other = Notification::create(['user_id' => $bob->id, 'type' => 'test', 'data' => []]);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own->id);
        $this->patchJson('/api/v1/notifications/' . $other->id . '/read')->assertStatus(404);
        $this->patchJson('/api/v1/notifications/' . $own->id . '/read')->assertOk();
        $this->patchJson('/api/v1/notifications/read-all')->assertOk();
        $this->assertTrue($own->refresh()->read_at !== null);
        $this->assertSame(null, $other->refresh()->read_at);
    }
}
