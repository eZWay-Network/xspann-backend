<?php

namespace Tests\Feature;

use App\Services\{Announcements, Conversations};
use Spark\Foundation\Application;
use Tests\Support\{CommunicationFixtures, HttpServer};
use Tests\TestCase;

class CommunicationConcurrencyTest extends TestCase
{
    use CommunicationFixtures;

    protected function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->mergeConfig(['database' => ['connections' => ['sqlite' => ['file' => $this->storagePath . '/concurrent.sqlite']]]]);

        return $app;
    }

    /** Two independent PHP servers contend for the same populated test database. */
    private function contend(string|array $method, string|array $path, array $inputs, array $tokens): array
    {
        $config = [];
        foreach (['app', 'auth', 'database', 'session', 'cache', 'queue', 'storage', 'cors', 'policies', 'artists', 'billing', 'communications', 'relay', 'testing'] as $key) {
            $config[$key] = config($key);
        }
        $file = $this->storagePath . '/concurrent-config.json';
        file_put_contents($file, json_encode($config, JSON_THROW_ON_ERROR));
        $servers = [];
        $handles = [];
        $multi = curl_multi_init();
        $pdo = db()->getPdo();
        $locked = false;
        // Separate workers serve one virtual origin, as behind a load balancer.
        $origin = parse_url(request()->getRootUrl());
        $host = $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : '');

        try {
            for ($index = 0; $index < 2; $index++) {
                $servers[$index] = new HttpServer(dirname(__DIR__) . '/Fixtures/http-router.php', $this->storagePath, ['XSPANN_HTTP_TEST_CONFIG' => $file]);
                $handles[$index] = curl_init($servers[$index]->url . '/api/v1' . (is_array($path) ? $path[$index] : $path));
                curl_setopt_array($handles[$index], [
                    CURLOPT_CUSTOMREQUEST => is_array($method) ? $method[$index] : $method,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => ['Idempotency-Key: concurrent-checkout-' . $index, 'Host: ' . $host, 'Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $tokens[$index]],
                    CURLOPT_POSTFIELDS => json_encode($inputs[$index], JSON_THROW_ON_ERROR),
                ]);
                curl_multi_add_handle($multi, $handles[$index]);
            }

            $pdo->exec('BEGIN IMMEDIATE');
            $locked = true;
            $deadline = microtime(true) + 0.2;
            do {
                curl_multi_exec($multi, $running);
                curl_multi_select($multi, 0.02);
            } while (microtime(true) < $deadline);
            $pdo->exec('COMMIT');
            $locked = false;

            do {
                curl_multi_exec($multi, $running);
                if ($running) {
                    curl_multi_select($multi, 0.1);
                }
            } while ($running);

            $responses = [];
            foreach ($handles as $handle) {
                $this->assertSame('', curl_error($handle));
                $responses[] = [
                    'status' => curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'body' => json_decode(curl_multi_getcontent($handle), true, flags: JSON_THROW_ON_ERROR),
                ];
            }

            return $responses;
        } finally {
            if ($locked) {
                $pdo->exec('ROLLBACK');
            }
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
            }
            curl_multi_close($multi);
            foreach ($servers as $server) {
                $server->stop();
            }
        }
    }


    public function testOppositeCreatorsResolveOneCanonicalConversation(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $results = $this->contend('POST', '/conversations', [['recipient_user_id' => $b->id], ['recipient_user_id' => $a->id]], [$this->token($a), $this->token($b)]);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame($results[0]['body']['data']['id'], $results[1]['body']['data']['id']);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('conversation_participants', 2);
    }

    public function testConcurrentDuplicateSendsCommitOneMessageAndOneNotice(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $conversation = Conversations::open($a->id, $b->id);
        $input = $this->messageInput();
        $token = $this->token($a);
        $results = $this->contend('POST', "/conversations/$conversation->id/messages", [$input, $input], [$token, $token]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 201], $statuses);
        $this->assertSame($results[0]['body']['data']['id'], $results[1]['body']['data']['id']);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function testConcurrentQuotaReservationsCannotAcceptAFifthBroadcast(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $this->subscriber($artist->id);
        for ($i = 0; $i < 3; $i++) {
            $this->campaign($owner, 'already-accepted-' . $i);
        }
        $first = Announcements::create($owner, ['title' => 'One', 'body' => 'First contender']);
        $second = Announcements::create($owner, ['title' => 'Two', 'body' => 'Second contender']);
        $token = $this->token($owner);
        $results = $this->contend('POST', ["/me/artist/announcements/$first->id/send", "/me/artist/announcements/$second->id/send"], [['version' => 1], ['version' => 1]], [$token, $token]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([202, 429], $statuses);
        $this->assertSame(4, query('announcements')->whereNotNull('accepted_at')->count());
        $this->assertDatabaseCount('announcement_deliveries', 4);
    }
    public function testConcurrentBlockAndSendHaveASerializedOutcome(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $conversation = Conversations::open($a->id, $b->id);
        $results = $this->contend('POST', ["/conversations/$conversation->id/messages", "/users/$a->id/block"],
            [$this->messageInput(), []], [$this->token($a), $this->token($b)]);
        $this->assertTrue(in_array($results[0]['status'], [201, 403], true));
        $this->assertSame(201, $results[1]['status']);
        $this->assertFalse(Conversations::canSend($a->id, $b->id));
        $this->asUser($a)->postJson("/api/v1/conversations/$conversation->id/messages", $this->messageInput())->assertForbidden();
        $this->assertSame($results[0]['status'] === 201 ? 1 : 0, query('messages')->count());
    }

    public function testConcurrentSendsCannotBothClaimTheLastRateLimitSlot(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $conversation = Conversations::open($a->id, $b->id);
        $this->app->mergeConfig(['communications' => ['sends_per_minute' => 1]]);
        $token = $this->token($a);
        $results = $this->contend('POST', "/conversations/$conversation->id/messages",
            [$this->messageInput('First'), $this->messageInput('Second')], [$token, $token]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 429], $statuses);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 1);
    }

    public function testConcurrentReadCursorsAlwaysKeepTheFurthestMessage(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $conversation = Conversations::open($a->id, $b->id);
        [$first] = Conversations::send($conversation->id, $a->id, 'First', $this->messageInput()['client_message_id']);
        [$last] = Conversations::send($conversation->id, $a->id, 'Last', $this->messageInput()['client_message_id']);
        $token = $this->token($b);
        $results = $this->contend('PATCH', "/conversations/$conversation->id/read",
            [['last_read_message_id' => $last->id], ['last_read_message_id' => $first->id]], [$token, $token]);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame($last->id, query('conversation_participants')->where('user_id', $b->id)->value('last_read_message_id'));
        $this->assertSame(0, query('notifications')->where('user_id', $b->id)->whereNull('read_at')->count());
    }

    public function testConcurrentUnsubscribeAndAcceptanceNeverDeliverAfterWithdrawal(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $fan = $this->subscriber($artist->id);
        $draft = Announcements::create($owner, ['title' => 'News', 'body' => 'Latest music']);
        $results = $this->contend(['POST', 'PUT'], ["/me/artist/announcements/$draft->id/send", "/artists/$artist->id/update-subscription"],
            [['version' => 1], ['subscribed' => false]], [$this->token($owner), $this->token($fan)]);
        $this->assertSame([202, 200], array_column($results, 'status'));
        Announcements::deliver($draft->id);
        $this->assertSame(0, query('announcement_deliveries')->where('status', 'delivered')->count());
        $this->assertSame(0, query('notifications')->where('type', 'artist_announcement')->count());
        $this->assertSame('completed', query('announcements')->where('id', $draft->id)->value('status'));
    }

}
