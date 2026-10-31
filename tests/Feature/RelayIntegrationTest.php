<?php

namespace Tests\Feature;

use App\Services\{Conversations, Realtime};
use Tests\Support\{CommunicationFixtures, RelayServer};
use Tests\TestCase;

class RelayIntegrationTest extends TestCase
{
    use CommunicationFixtures;

    public function testRealPrivateSubscriptionCommittedHintAndRestartCatchup(): void
    {
        $relayConfig = [
            'apps' => [['app_id' => '1', 'key' => 'test-key', 'secret' => 'test-secret', 'allowed_origins' => ['http://localhost'], 'client_events' => false]],
        ];
        $server = new RelayServer($this->storagePath, ['relay' => $relayConfig]);
        $this->app->mergeConfig(['relay' => [...$relayConfig, 'client' => ['host' => '127.0.0.1', 'port' => $server->port, 'scheme' => 'http', 'timeout' => 2]]]);
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $this->asUser($b)->postJson('/api/v1/realtime/session')->assertOk();
        $channel = 'private-session.' . query('realtime_sessions')->first()->channel_key;
        $socket = null;
        try {
            $socket = $server->connect();
            $connected = $server->receive($socket);
            $this->assertSame('pusher:connection_established', $connected['event']);
            $socketId = json_decode($connected['data'], true)['socket_id'];
            $auth = json_decode($this->postJson('/api/v1/broadcasting/auth', ['socket_id' => $socketId, 'channel_name' => $channel])->assertOk()->content(), true)['auth'];
            $server->send($socket, ['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => $auth]]);
            $this->assertSame('pusher_internal:subscription_succeeded', $server->receive($socket)['event']);
            $conversation = Conversations::open($a->id, $b->id);
            [$message] = Conversations::send($conversation->id, $a->id, 'Private payload', $this->messageInput()['client_message_id']);
            $event = query('realtime_outbox')->where('user_id', $b->id)->where('type', 'message.created')->first();
            Realtime::publish($event->id);
            $received = $server->receive($socket);
            $hint = json_decode($received['data'], true);
            $this->assertSame('message.created', $received['event']);
            $this->assertSame((string) $event->id, $hint['event_id']);
            $this->assertSame($message->id, $hint['resource_id']);
            $this->assertFalse(str_contains(json_encode($received), 'Private payload'));
            fclose($socket);
            $socket = null;
            $server->restart();
            [$missed] = Conversations::send($conversation->id, $a->id, 'During reconnect', $this->messageInput()['client_message_id']);
            Realtime::drain();
            $socket = $server->connect();
            $connected = $server->receive($socket);
            $socketId = json_decode($connected['data'], true)['socket_id'];
            $auth = json_decode($this->postJson('/api/v1/broadcasting/auth', ['socket_id' => $socketId, 'channel_name' => $channel])->assertOk()->content(), true)['auth'];
            $server->send($socket, ['event' => 'pusher:subscribe', 'data' => ['channel' => $channel, 'auth' => $auth]]);
            $this->assertSame('pusher_internal:subscription_succeeded', $server->receive($socket)['event']);
            $this->getJson("/api/v1/conversations/$conversation->id/messages?after_id=$message->id")->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $missed->id)->assertJsonPath('data.0.body', 'During reconnect');
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
            $server->stop();
        }
    }
}
