<?php

namespace Tests\Feature;

use App\Services\Promotions\{Boosts, Credits};
use Spark\Foundation\Application;
use Tests\Support\{PromotionFixtures, HttpServer};
use Tests\TestCase;

class PromotionConcurrencyTest extends TestCase
{
    use PromotionFixtures;

    protected function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->mergeConfig(['database' => ['connections' => ['sqlite' => ['file' => $this->storagePath . '/concurrent.sqlite']]]]);

        return $app;
    }

    /** Two independent PHP servers contend for the same populated test database. */
    private function contend(string|array $method, string|array $path, array $inputs, array $tokens, ?array $keys = null): array
    {
        $config = [];
        foreach (['app', 'auth', 'database', 'session', 'cache', 'queue', 'storage', 'cors', 'policies', 'artists', 'billing', 'promotions', 'communications', 'relay', 'testing'] as $key) {
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
                    CURLOPT_HTTPHEADER => ['Idempotency-Key: ' . ($keys[$index] ?? 'promotion-concurrent-' . $index), 'Host: ' . $host, 'Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $tokens[$index]],
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



    public function testTwoCampaignsCannotSpendTheSameCredit(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $a = Boosts::create($owner, $this->boostInput($this->makeVideo($owner)->id));
        $b = Boosts::create($owner, $this->boostInput($this->makeVideo($owner)->id));
        $token = $this->token($owner);
        $results = $this->contend('POST', ["/me/artist/boosts/$a->id/submit", "/me/artist/boosts/$b->id/submit"], [['version' => 1], ['version' => 1]], [$token, $token]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([202, 409], $statuses);
        $this->assertSame(500, Credits::balance($owner->id)['reserved_cents']);
        $this->assertSame(0, Credits::balance($owner->id)['available_cents']);
        $this->assertDatabaseCount('promotion_reservations', 1);
        $this->assertDatabaseCount('promotion_slots', 1);
    }

    public function testConcurrentRetryReturnsTheSameAcceptedResultAndGrant(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $boost = Boosts::create($owner, $this->boostInput($this->makeVideo($owner)->id));
        $token = $this->token($owner);
        $results = $this->contend('POST', "/me/artist/boosts/$boost->id/submit", [['version' => 1], ['version' => 1]], [$token, $token], ['same-submission-key', 'same-submission-key']);
        $this->assertSame([202, 202], array_column($results, 'status'));
        $this->assertSame($results[0]['body'], $results[1]['body']);
        $this->assertSame(1, query('promotion_ledger')->where('kind', 'grant')->count());
        $this->assertSame(1, query('promotion_ledger')->where('kind', 'reservation')->count());
        $this->assertDatabaseCount('promotion_operations', 1);
        $this->assertDatabaseCount('promotion_slots', 1);
    }

    public function testTwoArtistsCannotBothReserveTheFifthRotationSlot(): void
    {
        [$first] = $this->paidArtist();
        $this->promotionSetup();
        $owners = [$first];
        for ($i = 1; $i < 6; $i++) {
            $owner = $this->makeUser('artist' . $i);
            $this->makeArtist($owner);
            $this->asUser($owner);
            $attempt = $this->checkout(key: 'purchase-artist-' . $i);
            $invoice = $this->stripe->complete($attempt->session_id);
            $this->event('invoice.paid', $invoice);
            $owners[] = $owner;
        }
        $drafts = [];
        foreach ($owners as $i => $owner) {
            $draft = Boosts::create($owner, $this->boostInput($this->makeVideo($owner)->id));
            if ($i < 4) {
                Boosts::submit($owner, $draft->id, 1, 'initial-slot-' . $i);
            } else {
                $drafts[] = $draft;
            }
        }
        [$a, $b] = $drafts;
        $results = $this->contend('POST', ["/me/artist/boosts/$a->id/submit", "/me/artist/boosts/$b->id/submit"], [['version' => 1], ['version' => 1]], [$this->token($owners[4]), $this->token($owners[5])]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([202, 409], $statuses);
        $this->assertSame(5, query('promotion_slots')->whereNull('released_at')->count());
        $balances = [Credits::balance($owners[4]->id)['available_cents'], Credits::balance($owners[5]->id)['available_cents']];
        sort($balances);
        $this->assertSame([0, 500], $balances);
        $this->assertSame(5, query('promotion_reservations')->count());
    }
}
