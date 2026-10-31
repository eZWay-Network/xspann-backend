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
