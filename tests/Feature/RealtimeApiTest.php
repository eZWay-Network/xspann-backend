<?php

namespace Tests\Feature;

use App\Services\{Communications, Conversations, Realtime};
use Spark\Facades\Auth;
use Tests\Support\CommunicationFixtures;
use Tests\TestCase;

class RealtimeApiTest extends TestCase
{
    use CommunicationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->mergeConfig(['relay' => ['apps' => [['app_id' => '1', 'key' => 'test-key', 'secret' => 'test-secret', 'allowed_origins' => ['http://localhost'], 'client_events' => false]],
            'public' => ['host' => 'ws.example.test', 'port' => 443, 'tls' => true]]]);
    }

    public function testSessionsAreTokenBoundIdempotentAndAuthIsRawPusherJson(): void
    {
        $user = $this->makeUser();
        $token = $this->token($user);
        $this->withToken($token)->postJson('/api/v1/realtime/session')->assertOk();
        $row = query('realtime_sessions')->first();
        $channel = 'private-session.' . $row->channel_key;
        $this->postJson('/api/v1/realtime/session')->assertOk()->assertJsonPath('data.channel', $channel);
        $this->assertDatabaseCount('realtime_sessions', 1);
        $auth = $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertOk();
        $auth->assertJsonPath('auth', 'test-key:' . hash_hmac('sha256', '123.456:' . $channel, 'test-secret'));
        $this->assertFalse(str_contains($auth->content(), 'test-secret'));
        $this->asUser($user)->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertForbidden();
        $this->postJson('/api/v1/realtime/session')->assertOk();
        $this->assertDatabaseCount('realtime_sessions', 2);
        foreach (['public-room', 'private-session.unknown', 'presence-room'] as $name) {
            $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $name])->assertForbidden();
        }
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => 'bad', 'channel_name' => $channel])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('realtime_sessions', 1);
        $this->postJson('/api/v1/realtime/session')->assertUnauthorized();
    }

    public function testRefreshExpiryAndSuspensionRetireChannels(): void
    {
        $user = $this->makeUser();
        $this->asUser($user)->postJson('/api/v1/realtime/session')->assertOk();
        $channel = query('realtime_sessions')->first()->channel_key;
        $this->postJson('/api/v1/auth/token/refresh')->assertOk();
        $this->assertDatabaseCount('realtime_sessions', 0);
        $this->asUser($user)->postJson('/api/v1/realtime/session')->assertOk();
        $this->assertFalse($channel === query('realtime_sessions')->first()->channel_key);
        query('jwt_access_tokens')->where('user_id', $user->id)->update(['expire_at' => '2000-01-01 00:00:00']);
        $this->postJson('/api/v1/realtime/session')->assertUnauthorized();
        $this->asUser($user)->postJson('/api/v1/realtime/session')->assertOk();
        $user->update(['status' => 'suspended']);
        \App\Services\CommunicationPrivacy::maintain();
        $this->assertSame(0, query('realtime_sessions')->whereNull('retired_at')->count());
        $user->update(['status' => 'active']);
        $this->postJson('/api/v1/realtime/session')->assertUnauthorized();
    }

    public function testOfflineRelayDoesNotFailSendAndRetryHasOnlyHints(): void
    {
        $relay = new class extends \Spark\Relay\Relay {
            public bool $offline = true;
            public array $events = [];

            public function trigger(array|string $channels, string $event, array|string $data, ?string $socketId = null): mixed
            {
                if ($this->offline) {
                    throw new \RuntimeException('Offline');
                }
                $this->events[] = [$channels, $event, $data];

                return true;
            }
        };
        $this->app->instance(\Spark\Relay\Relay::class, $relay);
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $this->asUser($b)->postJson('/api/v1/realtime/session')->assertOk();
        $this->asUser($b)->postJson('/api/v1/realtime/session')->assertOk();
        $id = Conversations::open($a->id, $b->id)->id;
        $this->asUser($a)->postJson("/api/v1/conversations/$id/messages", $this->messageInput('Secret body'))->assertCreated();
        $event = query('realtime_outbox')->where('user_id', $b->id)->where('type', 'message.created')->first();
        Realtime::publish($event->id);
        $this->assertSame(null, query('realtime_outbox')->where('id', $event->id)->value('completed_at'));
        $this->assertSame(1, query('realtime_outbox')->where('id', $event->id)->value('attempts'));
        $relay->offline = false;
        Realtime::publish($event->id);
        Realtime::publish($event->id);
        $this->assertCount(2, $relay->events);
        $this->assertSame(['event_id', 'type', 'occurred_at', 'resource_id', 'version'], array_keys($relay->events[0][2]));
        $this->assertFalse(str_contains(json_encode($relay->events), 'Secret body'));
        $this->assertSame($relay->events[0][2]['event_id'], $relay->events[1][2]['event_id']);
    }

    public function testBlockAndLogoutBeforePublishingPreventUnauthorizedHints(): void
    {
        $relay = new class extends \Spark\Relay\Relay {
            public array $events = [];
            public function trigger(array|string $channels, string $event, array|string $data, ?string $socketId = null): mixed
            {
                $this->events[] = $data;
                return true;
            }
        };
        $this->app->instance(\Spark\Relay\Relay::class, $relay);
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $this->asUser($b)->postJson('/api/v1/realtime/session')->assertOk();
        $id = Conversations::open($a->id, $b->id)->id;
        Conversations::send($id, $a->id, 'Secret', $this->messageInput()['client_message_id']);
        $this->postJson('/api/v1/users/' . $a->id . '/block')->assertCreated();
        Realtime::drain();
        $this->assertCount(0, $relay->events);
        Realtime::record($b->id, 'notifications.updated', $b->id, 0);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        Realtime::drain();
        $this->assertCount(0, $relay->events);
    }

    public function testMissingConfigurationLeavesRestUsable(): void
    {
        $this->app->mergeConfig(['relay' => ['apps' => [['key' => '', 'secret' => '']]]]);
        $user = $this->makeUser();
        $this->asUser($user)->postJson('/api/v1/realtime/session')->assertStatus(503);
        $this->getJson('/api/v1/conversations')->assertOk();
        $this->getJson('/api/v1/notifications/unread-count')->assertOk();
    }
    public function testMutingBeforeOutboxPublishSuppressesAlertButKeepsMessageHint(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $conversation = Conversations::open($a->id, $b->id);
        Conversations::send($conversation->id, $a->id, 'Already committed', $this->messageInput()['client_message_id']);
        $alert = query('realtime_outbox')->where('user_id', $b->id)->where('type', 'notification.created')->first();
        $message = query('realtime_outbox')->where('user_id', $b->id)->where('type', 'message.created')->first();
        $this->assertTrue(Realtime::permitted($alert));
        Conversations::mute($conversation->id, $b->id, true);
        $this->assertFalse(Realtime::permitted($alert));
        $this->assertTrue(Realtime::permitted($message));
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function testRecurringRealtimeConsumerProcessesCommittedHintsAndReschedulesPromptly(): void
    {
        $user = $this->makeUser();
        Realtime::record($user->id, 'notifications.updated', $user->id, 0);
        $this->app->withQueue(then: require dirname(__DIR__, 2) . '/bootstrap/jobs.php');
        $queue = \Spark\Facades\Queue::connection();
        $this->assertCount(1, $queue->getJobs(queue: 'realtime'));
        foreach (['notifications', 'billing', 'releases', 'privacy'] as $name) {
            $this->assertCount(1, $queue->getJobs(queue: $name));
        }
        $queue->work(once: true, timeout: 5, sleep: 0, queue: 'realtime');
        $this->assertNotNull(query('realtime_outbox')->first()->completed_at);
        $jobs = $queue->getJobs(queue: 'realtime');
        $this->assertCount(1, $jobs);
        $this->assertTrue($jobs[0]->getScheduledTime()->getTimestamp() >= time());
        $this->assertTrue($jobs[0]->getScheduledTime()->getTimestamp() <= time() + 2);
        $this->assertSame([], $queue->getFailedJobs());
    }

}
