<?php

namespace Tests\Feature;

use App\Models\{Follow, Notification};
use App\Services\{Communications, Conversations};
use Spark\Facades\DB;
use Tests\Support\CommunicationFixtures;
use Tests\TestCase;

class ConversationApiTest extends TestCase
{
    use CommunicationFixtures;

    public function testCanonicalPairIsolationAndEverySendPermission(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $eve = $this->makeUser('eve');
        $this->postJson('/api/v1/conversations', ['recipient_user_id' => $bob->id])->assertUnauthorized();
        $this->asUser($alice)->postJson('/api/v1/conversations', ['recipient_user_id' => $bob->id])->assertForbidden();
        $this->mutual($alice, $bob);
        $this->postJson('/api/v1/conversations', ['recipient_user_id' => $bob->id])->assertOk();
        $id = query('conversations')->first()->id;
        $this->asUser($bob)->postJson('/api/v1/conversations', ['recipient_user_id' => $alice->id])->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('conversations', 1);
        $this->asUser($eve)->getJson("/api/v1/conversations/$id")->assertNotFound();
        $this->getJson("/api/v1/conversations/$id/messages")->assertNotFound();
        $this->postJson("/api/v1/conversations/$id/messages", $this->messageInput())->assertNotFound();
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonCount(0, 'data');
        $this->asUser($alice)->postJson("/api/v1/conversations/$id/messages", $this->messageInput())->assertCreated();
        $this->deleteJson('/api/v1/users/' . $bob->id . '/follow')->assertOk();
        $this->postJson("/api/v1/conversations/$id/messages", $this->messageInput())->assertForbidden();
        $this->getJson("/api/v1/conversations/$id/messages")->assertOk()->assertHeader('Cache-Control', 'no-store')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/conversations/$id")->assertOk()->assertJsonPath('data.can_send', false);
    }

    public function testInboxShowsTheVisiblePeerAndPaginatesByLatestActivity(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $carol = $this->makeUser('carol');
        $bob->update(['name' => 'Bob Rivers', 'avatar' => 'https://cdn.example.test/bob.png']);
        $this->mutual($alice, $bob);
        $this->mutual($alice, $carol);
        $first = Conversations::open($alice->id, $bob->id);
        $second = Conversations::open($alice->id, $carol->id);
        DB::table('conversations')->where('id', $first->id)->update(['created_at' => '2024-01-01 12:00:00']);
        DB::table('conversations')->where('id', $second->id)->update(['created_at' => '2024-02-01 12:00:00']);
        Conversations::send($first->id, $bob->id, 'New reply', $this->messageInput()['client_message_id']);

        $page = $this->asUser($alice)->getJson('/api/v1/conversations?limit=1')->assertOk();
        $page->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.recipient', [
                'id' => $bob->id,
                'name' => 'Bob Rivers',
                'username' => 'bob',
                'avatar' => 'https://cdn.example.test/bob.png',
            ]);
        $cursor = rawurlencode($page->json('meta.next_cursor'));
        $this->getJson("/api/v1/conversations?limit=1&cursor=$cursor")->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('meta.next_cursor', null);
        $this->asUser($bob)->getJson("/api/v1/conversations/$first->id")->assertOk()
            ->assertJsonPath('data.recipient.username', 'alice');

        $this->postJson("/api/v1/users/$alice->id/block")->assertCreated();
        foreach ([$alice, $bob] as $viewer) {
            $this->asUser($viewer)->getJson("/api/v1/conversations/$first->id")->assertOk()
                ->assertJsonPath('data.recipient', null)
                ->assertJsonPath('data.can_send', false);
        }
    }

    public function testSenderUuidDeduplicationEncryptionAndRollback(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $id = Conversations::open($a->id, $b->id)->id;
        $input = $this->messageInput();
        $this->asUser($a)->postJson("/api/v1/conversations/$id/messages", $input)->assertCreated()->assertJsonPath('data.body', 'Private hello');
        $this->postJson("/api/v1/conversations/$id/messages", $input)->assertOk();
        $this->postJson("/api/v1/conversations/$id/messages", [...$input, 'body' => 'Different'])->assertStatus(409);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertFalse(str_contains(query('messages')->first()->body, 'Private hello'));
        $this->assertFalse(str_contains(json_encode(query('realtime_outbox')->get()->toArray()), 'Private hello'));
        $count = query('realtime_outbox')->count();
        try {
            DB::transaction(function () use ($a, $id): void {
                Conversations::send($id, $a->id, 'Rolled back', $this->messageInput()['client_message_id']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('realtime_outbox', $count);
    }

    public function testSenderCanEditEncryptedMessageWithoutDuplicatingAlertsOrChangingSendRetry(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $this->mutual($alice, $bob);
        $conversation = Conversations::open($alice->id, $bob->id);
        $input = $this->messageInput();
        [$message] = Conversations::send($conversation->id, $alice->id, $input['body'], $input['client_message_id']);
        $url = "/api/v1/conversations/$conversation->id/messages/$message->id";

        $this->asUser($alice)->patchJson($url, ['body' => 'Edited hello'])->assertOk()
            ->assertHeader('Cache-Control', 'no-store')->assertJsonPath('data.body', 'Edited hello')
            ->assertJsonPath('data.client_message_id', $input['client_message_id']);
        $stored = query('messages')->where('id', $message->id)->first();
        $this->assertFalse(str_contains($stored->body, 'Edited hello'));
        $this->assertSame($message->request_hash, $stored->request_hash);
        $this->assertSame($message->created_at, $stored->created_at);
        $this->assertDatabaseCount('notifications', 1);
        foreach ([$alice, $bob] as $viewer) {
            $this->assertDatabaseHas('realtime_outbox', ['user_id' => $viewer->id, 'type' => 'conversation.updated', 'resource_id' => $conversation->id, 'version' => 3]);
            $this->asUser($viewer)->getJson("/api/v1/conversations/$conversation->id")->assertOk()
                ->assertJsonPath('data.version', 3)->assertJsonPath('data.last_message.body', 'Edited hello');
        }
        $this->asUser($alice)->patchJson($url, ['body' => 'Edited hello'])->assertOk();
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'version' => 3]);
        $this->postJson("/api/v1/conversations/$conversation->id/messages", $input)->assertOk()->assertJsonPath('data.body', 'Edited hello');
        $this->postJson("/api/v1/conversations/$conversation->id/messages", [...$input, 'body' => 'Edited hello'])->assertStatus(409);
        $this->assertDatabaseCount('messages', 1);
    }

    public function testMessageEditsExpireFiveMinutesAfterOriginalSend(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $this->mutual($alice, $bob);
        $conversation = Conversations::open($alice->id, $bob->id);
        [$message] = Conversations::send($conversation->id, $alice->id, 'Original', $this->messageInput()['client_message_id']);
        $url = "/api/v1/conversations/$conversation->id/messages/$message->id";
        $createdAt = \App\Services\Billing\Billing::timestamp(time() - 240);
        DB::table('messages')->where('id', $message->id)->update(['created_at' => $createdAt]);

        $this->asUser($alice)->patchJson($url, ['body' => 'Edited in time'])->assertOk()
            ->assertJsonPath('data.body', 'Edited in time');
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'created_at' => $createdAt]);
        $events = query('realtime_outbox')->count();
        foreach ([300, 301] as $age) {
            DB::table('messages')->where('id', $message->id)->update([
                'created_at' => \App\Services\Billing\Billing::timestamp(time() - $age),
            ]);
            $this->patchJson($url, ['body' => 'Too late'])->assertStatus(409);
        }
        $this->getJson("/api/v1/conversations/$conversation->id/messages")->assertOk()
            ->assertJsonPath('data.0.body', 'Edited in time');
        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'version' => 3]);
        $this->assertDatabaseCount('realtime_outbox', $events);
    }

    public function testMessageChangesRequireSenderAndMatchingConversation(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $eve = $this->makeUser('eve');
        $this->mutual($alice, $bob);
        $this->mutual($alice, $eve);
        $conversation = Conversations::open($alice->id, $bob->id);
        $other = Conversations::open($alice->id, $eve->id);
        [$message] = Conversations::send($conversation->id, $alice->id, 'Original', $this->messageInput()['client_message_id']);
        $url = "/api/v1/conversations/$conversation->id/messages/$message->id";
        $this->patchJson($url, ['body' => 'Changed'])->assertUnauthorized();
        $this->deleteJson($url)->assertUnauthorized();
        foreach ([$bob, $eve] as $viewer) {
            $this->asUser($viewer)->patchJson($url, ['body' => 'Changed'])->assertNotFound();
            $this->deleteJson($url)->assertNotFound();
        }
        $this->asUser($alice)->patchJson("/api/v1/conversations/$other->id/messages/$message->id", ['body' => 'Changed'])->assertNotFound();
        $this->deleteJson("/api/v1/conversations/$other->id/messages/$message->id")->assertNotFound();
        $this->patchJson("/api/v1/conversations/$conversation->id/messages/99999", ['body' => 'Changed'])->assertNotFound();
        $this->deleteJson("/api/v1/conversations/$conversation->id/messages/99999")->assertNotFound();
        $this->getJson("/api/v1/conversations/$conversation->id/messages")->assertOk()->assertJsonPath('data.0.body', 'Original');
    }

    public function testMessageEditValidationAndCurrentPermission(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $this->mutual($alice, $bob);
        $conversation = Conversations::open($alice->id, $bob->id);
        [$message] = Conversations::send($conversation->id, $alice->id, 'Original', $this->messageInput()['client_message_id']);
        $url = "/api/v1/conversations/$conversation->id/messages/$message->id";
        $this->asUser($alice);
        foreach (['', '   ', str_repeat('a', 2001), "bad\0text", null] as $body) {
            $this->patchJson($url, ['body' => $body])->assertStatus(422);
        }
        $this->patchJson($url, [])->assertStatus(422);
        $this->patchJson($url, ['body' => 'Changed', 'sender_user_id' => $bob->id])->assertStatus(422);
        $this->deleteJson($url, ['body' => 'Changed'])->assertStatus(422);
        DB::table('messages')->where('id', $message->id)->update(['moderation_status' => 'restricted']);
        $this->patchJson($url, ['body' => 'Changed'])->assertStatus(409);
        DB::table('messages')->where('id', $message->id)->update(['moderation_status' => 'approved']);
        $this->deleteJson("/api/v1/users/$bob->id/follow")->assertOk();
        $this->patchJson($url, ['body' => 'Changed'])->assertForbidden();
        $this->getJson("/api/v1/conversations/$conversation->id/messages")->assertOk()->assertJsonPath('data.0.body', 'Original');
    }

    public function testSenderCanDeleteMessageAndLinkedAlertWhilePreservingReportEvidence(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $this->mutual($alice, $bob);
        $conversation = Conversations::open($alice->id, $bob->id);
        [$first] = Conversations::send($conversation->id, $alice->id, 'Earlier', $this->messageInput()['client_message_id']);
        [$message] = Conversations::send($conversation->id, $alice->id, 'Reported text', $this->messageInput()['client_message_id']);
        $url = "/api/v1/conversations/$conversation->id/messages/$message->id";
        $this->asUser($bob)->postJson('/api/v1/reports', ['message_id' => $message->id, 'reason' => 'Abuse'])->assertCreated();
        $evidence = query('reports')->first()->evidence;
        $this->asUser($alice)->patchJson($url, ['body' => 'Edited text'])->assertOk();
        $this->postJson("/api/v1/users/$bob->id/block")->assertCreated();
        $this->deleteJson($url)->assertNoContent();
        $this->deleteJson($url)->assertNotFound();
        $this->assertDatabaseMissing('messages', ['id' => $message->id]);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame($first->id, Notification::first()->data['message_id']);
        $this->assertSame($evidence, query('reports')->first()->evidence);
        foreach ([$alice, $bob] as $viewer) {
            $this->assertDatabaseHas('realtime_outbox', ['user_id' => $viewer->id, 'type' => 'conversation.updated', 'resource_id' => $conversation->id, 'version' => 5]);
            $this->asUser($viewer)->getJson("/api/v1/conversations/$conversation->id/messages")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id);
            $this->getJson("/api/v1/conversations/$conversation->id")->assertOk()->assertJsonPath('data.last_message.id', $first->id);
        }
        $this->getJson('/api/v1/me/communication-summary')->assertOk()
            ->assertJsonPath('data.unread_messages', 1)->assertJsonPath('data.unread_notifications', 1);
        $this->asUser($alice)->deleteJson("/api/v1/conversations/$conversation->id/messages/$first->id")->assertNoContent();
        $this->asUser($bob)->getJson("/api/v1/conversations/$conversation->id")->assertOk()
            ->assertJsonPath('data.last_message', null)->assertJsonPath('data.unread_count', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function testMonotonicReadCursorsAndCatchupPagination(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $c = $this->makeUser('charlie');
        $this->mutual($a, $b);
        $this->mutual($a, $c);
        $id = Conversations::open($a->id, $b->id)->id;
        $other = Conversations::open($a->id, $c->id)->id;
        for ($i = 0; $i < 3; $i++) {
            Conversations::send($id, $a->id, "Message $i", $this->messageInput()['client_message_id']);
        }
        [$foreign] = Conversations::send($other, $a->id, 'Elsewhere', $this->messageInput()['client_message_id']);
        $ids = query('messages')->where('conversation_id', $id)->orderBy('id')->pluck('id');
        $this->asUser($b)->getJson("/api/v1/conversations/$id/messages?after_id=0&limit=2")->assertOk()
            ->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('meta.next_after_id', $ids[1])->assertJsonPath('meta.has_more', true);
        $this->getJson("/api/v1/conversations/$id/messages?before_id={$ids[2]}")->assertOk()->assertJsonPath('data.0.id', $ids[1]);
        $this->getJson("/api/v1/conversations/$id/messages?after_id=0&before_id=5")->assertStatus(422);
        $this->patchJson("/api/v1/conversations/$id/read", ['last_read_message_id' => $foreign->id])->assertStatus(422);
        $this->patchJson("/api/v1/conversations/$id/read", ['last_read_message_id' => $ids[2]])->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->patchJson("/api/v1/conversations/$id/read", ['last_read_message_id' => $ids[0]])->assertOk()->assertJsonPath('data.last_read_message_id', $ids[2]);
        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonPath('meta.unread_count', 0);
    }

    public function testSummaryAndBulkReadKeepActivityAndThreadCountsIndependent(): void
    {
        $this->getJson('/api/v1/me/communication-summary')->assertUnauthorized();
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $carol = $this->makeUser('carol');
        $this->mutual($alice, $bob);
        $this->mutual($carol, $bob);
        $thread = Conversations::open($alice->id, $bob->id);
        $other = Conversations::open($carol->id, $bob->id);
        for ($i = 0; $i < 12; $i++) {
            [$last] = Conversations::send($thread->id, $alice->id, 'Fixture', $this->messageInput()['client_message_id']);
        }
        Conversations::send($other->id, $carol->id, 'Other thread', $this->messageInput()['client_message_id']);
        $this->asUser($bob)->getJson('/api/v1/me/communication-summary')->assertOk()
            ->assertJsonPath('data.unread_messages', 13)->assertJsonPath('data.unread_notifications', 13);
        $activity = fn() => query('realtime_outbox')->where('user_id', $bob->id)->where('type', 'notifications.updated')->count();
        $before = $activity();
        Conversations::read($thread->id, $bob->id, $last->id);
        Conversations::read($thread->id, $bob->id, $last->id);
        $this->assertSame($before + 1, $activity());
        $this->getJson('/api/v1/me/communication-summary')->assertOk()
            ->assertJsonPath('data.unread_messages', 1)->assertJsonPath('data.unread_notifications', 1);
        $this->patchJson('/api/v1/notifications/read-all')->assertOk();
        $this->getJson('/api/v1/me/communication-summary')->assertOk()
            ->assertJsonPath('data.unread_messages', 1)->assertJsonPath('data.unread_notifications', 0);
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonPath('meta.unread_count', 1);
    }

    public function testMuteOnlyAffectsOwnAlertsAndDisabledMessagingStopsSends(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $id = Conversations::open($a->id, $b->id)->id;
        $this->asUser($b)->patchJson("/api/v1/conversations/$id/preferences", ['muted' => true])->assertOk();
        $this->asUser($a)->postJson("/api/v1/conversations/$id/messages", $this->messageInput())->assertCreated();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 0);
        $this->asUser($b)->postJson("/api/v1/conversations/$id/messages", $this->messageInput())->assertCreated();
        $this->assertDatabaseCount('notifications', 1);
        $this->patchJson('/api/v1/me/communication-preferences', ['allow_direct_messages' => false])->assertOk();
        $this->asUser($a)->postJson("/api/v1/conversations/$id/messages", $this->messageInput())->assertForbidden();
    }

    public function testValidationAndDurableUserRateLimits(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $id = Conversations::open($a->id, $b->id)->id;
        $this->asUser($a);
        foreach (['', '   ', str_repeat('a', 2001), "bad\0text"] as $body) {
            $this->postJson("/api/v1/conversations/$id/messages", $this->messageInput($body))->assertStatus(422);
        }
        $this->postJson("/api/v1/conversations/$id/messages", [...$this->messageInput(), 'sender_user_id' => $b->id])->assertStatus(422);
        $this->postJson("/api/v1/conversations/$id/messages", ['body' => 'Text', 'client_message_id' => 'invalid'])->assertStatus(422);
        $this->patchJson('/api/v1/me/communication-preferences', ['allow_direct_messages' => 'false'])->assertStatus(422);
        $this->patchJson('/api/v1/me/communication-preferences', ['allow_direct_messages' => null])->assertStatus(422);
        $this->app->mergeConfig(['communications' => ['sends_per_minute' => 2, 'conversations_per_hour' => 1]]);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson("/api/v1/conversations/$id/messages", $this->messageInput())->assertCreated();
        }
        $this->postJson("/api/v1/conversations/$id/messages", $this->messageInput())->assertTooManyRequests();
        $c = $this->makeUser('charlie');
        $this->mutual($a, $c);
        $this->postJson('/api/v1/conversations', ['recipient_user_id' => $c->id])->assertTooManyRequests();
        $this->postJson('/api/v1/conversations', ['recipient_user_id' => $b->id])->assertOk();
    }

    public function testBlockPreservesParticipantEvidenceButStopsAlertsAndNewSends(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $eve = $this->makeUser('eve');
        $this->mutual($a, $b);
        $id = Conversations::open($a->id, $b->id)->id;
        [$message] = Conversations::send($id, $a->id, 'Report this evidence', $this->messageInput()['client_message_id']);
        $this->asUser($b)->postJson('/api/v1/users/' . $a->id . '/block')->assertCreated();
        $this->postJson('/api/v1/reports', ['message_id' => $message->id, 'reason' => 'Abuse'])->assertCreated();
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.data.message', 'This activity is no longer available.');
        $this->getJson("/api/v1/conversations/$id/messages")->assertOk()->assertJsonPath('data.0.body', 'Report this evidence');
        $this->postJson("/api/v1/conversations/$id/messages", $this->messageInput())->assertForbidden();
        $this->asUser($eve)->postJson('/api/v1/reports', ['message_id' => $message->id, 'reason' => 'Abuse'])->assertNotFound();
        $this->assertFalse(str_contains(query('reports')->first()->evidence, 'Report this evidence'));
    }
}
