<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\User;
use App\Models\Video;
use App\Services\SocialNotifications;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    public function testSocialActionsPersistUsefulNotificationsWithoutRequestDuplicates(): void
    {
        $owner = $this->makeUser();
        $actor = $this->makeUser('bob');
        $video = $this->makeVideo($owner);
        $comment = Comment::create(['user_id' => $owner->id, 'video_id' => $video->id, 'body' => 'Hello']);
        $this->asUser($actor);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/videos/' . $video->id . '/like')->assertStatus($i ? 200 : 201);
            $this->postJson('/api/v1/users/' . $owner->id . '/follow')->assertStatus(201);
            $this->postJson('/api/v1/comments/' . $comment->id . '/reaction', ['reaction_type' => $i ? 'love' : 'like'])->assertOk();
            $this->postJson('/api/v1/videos/' . $video->id . '/share')->assertStatus(201);
        }

        $this->assertDatabaseCount('notifications', 4);
        foreach ([Notification::VIDEO_LIKED, Notification::USER_FOLLOWED, Notification::COMMENT_REACTED, Notification::VIDEO_SHARED] as $type) {
            $notice = Notification::where('type', $type)->first();
            $this->assertSame($owner->id, $notice->user_id);
            $this->assertSame($actor->id, $notice->data['actor']['id']);
            $this->assertFalse(isset($notice->data['actor']['email']));
            $this->assertSame(null, $notice->read_at);
        }

        $this->asUser($owner)->getJson('/api/v1/notifications?unread=1&limit=2')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.unread_count', 4)
            ->assertJsonPath('meta.total', 4);
    }

    public function testCommentsAndRepliesReachTheRightRecipientsOnce(): void
    {
        $owner = $this->makeUser();
        $parentAuthor = $this->makeUser('bob');
        $actor = $this->makeUser('carol');
        $video = $this->makeVideo($owner);
        $path = '/api/v1/videos/' . $video->id . '/comments';
        $parent = Comment::create(['user_id' => $parentAuthor->id, 'video_id' => $video->id, 'body' => 'Parent']);
        $this->asUser($actor);
        $id = $this->postJson($path, ['body' => 'A reply', 'parent_id' => $parent->id])->assertStatus(201)->json('data.id');
        $this->assertDatabaseCount('notifications', 2);
        $notice = Notification::where('user_id', $parentAuthor->id)->first();
        $this->assertSame(Notification::COMMENT_REPLIED, $notice->type);
        $this->assertSame($id, $notice->data['comment_id']);
        $this->assertSame($parent->id, $notice->data['parent_id']);
        $this->assertSame('A reply', $notice->data['excerpt']);
        $this->assertSame(Notification::VIDEO_COMMENTED, Notification::where('user_id', $owner->id)->first()->type);

        $ownersComment = Comment::create(['user_id' => $owner->id, 'video_id' => $video->id, 'body' => 'Owner']);
        $this->postJson($path, ['body' => 'Reply to owner', 'parent_id' => $ownersComment->id])->assertStatus(201);
        $this->assertDatabaseCount('notifications', 3);
        $this->postJson($path, ['body' => 'Top-level comment'])->assertStatus(201);
        $this->assertDatabaseCount('notifications', 4);
        $this->postJson($path, ['body' => 'Invalid', 'parent_id' => 9999])->assertStatus(422);
        $this->assertDatabaseCount('notifications', 4);
    }

    public function testSelfActivityAndAnonymousSharesDoNotNotify(): void
    {
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner);
        $this->postJson('/api/v1/videos/' . $video->id . '/share')->assertStatus(201);
        $this->asUser($owner);
        $this->postJson('/api/v1/videos/' . $video->id . '/like')->assertStatus(201);
        $this->postJson('/api/v1/videos/' . $video->id . '/share')->assertStatus(201);
        $id = $this->postJson('/api/v1/videos/' . $video->id . '/comments', ['body' => 'Mine'])->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/comments/' . $id . '/reaction')->assertOk();
        $this->assertDatabaseCount('notifications', 0);
    }

    public function testUnreadFilterAndReadActionsAreScopedToTheRecipient(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser('bob');
        $first = Notification::create(['user_id' => $owner->id, 'type' => 'test', 'data' => []]);
        $second = Notification::create(['user_id' => $owner->id, 'type' => 'test', 'data' => []]);
        $foreign = Notification::create(['user_id' => $other->id, 'type' => 'test', 'data' => []]);
        $this->asUser($owner);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.id', $second->id);
        $this->patchJson('/api/v1/notifications/' . $first->id . '/read')->assertOk();
        $this->getJson('/api/v1/notifications?unread=1')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id)->assertJsonPath('meta.unread_count', 1);
        $this->getJson('/api/v1/notifications?unread=invalid')->assertStatus(422);
        $this->patchJson('/api/v1/notifications/' . $foreign->id . '/read')->assertStatus(404);
        $this->patchJson('/api/v1/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/notifications?unread=1')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.unread_count', 0);
        $this->assertSame(null, $foreign->refresh()->read_at);
    }

    public function testReplyDoesNotNotifyAnAuthorWhoLostVideoAccess(): void
    {
        $owner = $this->makeUser();
        $formerViewer = $this->makeUser('bob');
        $video = $this->makeVideo($owner, ['visibility' => 'private']);
        $parent = Comment::create(['user_id' => $formerViewer->id, 'video_id' => $video->id, 'body' => 'Old comment']);
        $this->asUser($owner)->postJson('/api/v1/videos/' . $video->id . '/comments', [
            'body' => 'Private reply',
            'parent_id' => $parent->id,
        ])->assertStatus(201);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function testNotificationFailureRollsBackTheEntireAction(): void
    {
        $owner = $this->makeUser();
        $actor = $this->makeUser('bob');
        $video = $this->makeVideo($owner);
        $this->app->instance(SocialNotifications::class, new class extends SocialNotifications {
            public function liked(User $actor, Video $video): void
            {
                parent::liked($actor, $video);

                throw new \RuntimeException('Simulated notification failure.');
            }
        });

        $failed = false;

        try {
            $this->asUser($actor)->postJson('/api/v1/videos/' . $video->id . '/like');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated notification failure.', $exception->getMessage());
            $failed = true;
        }

        $this->assertTrue($failed);
        $this->assertDatabaseCount('likes', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertSame(0, $video->refresh()->likes_count);
    }

    public function testAvatarUrlsAreResolvedAtReadTimeAndBlocksPreventNewNotices(): void
    {
        $this->useS3(['temporary_urls' => true]);
        $owner = $this->makeUser();
        $actor = $this->makeUser('bob');
        $avatarPath = storage('s3')->url('avatars/' . $actor->id . '/bob.jpg');
        $actor->update(['avatar' => $avatarPath]);
        $video = $this->makeVideo($owner);
        $this->asUser($actor)->postJson('/api/v1/videos/' . $video->id . '/like')->assertStatus(201);
        $notice = Notification::query()->first();
        $this->assertSame($avatarPath, $notice->data['actor']['avatar']);
        $avatar = $this->asUser($owner)->getJson('/api/v1/notifications')->assertOk()->json('data.0.data.actor.avatar');
        $this->assertTrue(str_contains($avatar, 'X-Amz-Signature='));
        $this->postJson('/api/v1/users/' . $actor->id . '/block')->assertStatus(201);
        $this->asUser($actor)->postJson('/api/v1/videos/' . $video->id . '/comments', ['body' => 'Blocked'])->assertStatus(404);
        $this->postJson('/api/v1/users/' . $owner->id . '/follow')->assertStatus(404);
        $this->assertDatabaseCount('notifications', 1);
    }
}
