<?php

namespace Tests\Feature;

use App\Models\ArtistPlaylist;
use Spark\Foundation\Application;
use Tests\Support\{ArtistFixtures, HttpServer};
use Tests\TestCase;

class ArtistConcurrencyTest extends TestCase
{
    use ArtistFixtures, \Tests\Support\BillingFixtures;

    protected function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->mergeConfig(['database' => ['connections' => ['sqlite' => ['file' => $this->storagePath . '/concurrent.sqlite']]]]);

        return $app;
    }

    /** Two independent PHP servers contend for the same populated test database. */
    private function contend(string $method, string $path, array $inputs, array $tokens): array
    {
        $config = [];
        foreach (['app', 'auth', 'database', 'session', 'cache', 'queue', 'storage', 'cors', 'policies', 'artists', 'billing', 'testing'] as $key) {
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
                $handles[$index] = curl_init($servers[$index]->url . '/api/v1' . $path);
                curl_setopt_array($handles[$index], [
                    CURLOPT_CUSTOMREQUEST => $method,
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

    public function testConcurrentPlaylistCreationCannotExceedFreeLimit(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $token = $this->token($owner);
        $results = $this->contend('POST', '/me/artist/playlists', [['title' => 'First'], ['title' => 'Second']], [$token, $token]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 403], $statuses, json_encode($results));
        $this->assertDatabaseCount('artist_playlists', 1);
    }

    public function testConcurrentReordersAcceptExactlyOneVersionAndPreserveCompleteOrder(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner);
        $first = $this->makeVideo($owner);
        $second = $this->makeVideo($owner);
        $playlist = ArtistPlaylist::create(['artist_id' => $artist->id, 'title' => 'Race'])->refresh();
        $token = $this->token($owner);
        $results = $this->contend('PUT', "/me/artist/playlists/$playlist->id/items", [
            ['version' => 1, 'video_ids' => [$first->id, $second->id]],
            ['version' => 1, 'video_ids' => [$second->id, $first->id]],
        ], [$token, $token]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses, json_encode($results));
        $this->assertSame(2, $playlist->refresh()->version);
        $winner = $results[0]['status'] === 200 ? $results[0] : $results[1];
        $stored = query('artist_playlist_items')->where('playlist_id', $playlist->id)->orderBy('position')->pluck('video_id');
        $this->assertSame(array_column($winner['body']['data']['items'], 'video_id'), $stored);
        $this->assertCount(2, $stored);
    }

    public function testConcurrentSlugsAndEnrollmentRespectUniqueConstraints(): void
    {
        $alice = $this->makeUser();
        $bob = $this->makeUser('bob');
        $profile = ['stage_name' => 'Shared name', 'slug' => 'unique-artist'];
        $results = $this->contend('PUT', '/me/artist', [$profile, $profile], [$this->token($alice), $this->token($bob)]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 422], $statuses, json_encode($results));
        $this->assertDatabaseCount('artists', 1);
    }

    public function testConcurrentReleasePublicationAcceptsOnlyOneVersion(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        storage('local')->put('videos/1/private.mp4', 'private bytes');
        $video = $this->makeVideo($owner, [
            'storage_path' => 'local:videos/1/private.mp4', 'status' => 'draft',
            'processing_state' => 'ready', 'publication_state' => 'draft', 'publication_intent' => 'draft',
        ]);
        $token = $this->token($owner);
        $results = $this->contend('POST', "/me/artist/releases/$video->id/publish", [['version' => 1], ['version' => 1]], [$token, $token]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses, json_encode($results));
        $this->assertSame('published', $video->refresh()->publication_state);
        $this->assertSame(2, $video->version);
    }

    public function testConcurrentDifferentCheckoutKeysCannotCreateOverlappingPurchases(): void
    {
        $this->billingSetup();
        $this->app->mergeConfig(['testing' => ['fake_stripe' => true]]);
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $token = $this->token($owner);
        $input = ['plan_code' => 'artist_pro', 'offer_version' => config('billing.offer_version')];
        $results = $this->contend('POST', '/billing/checkout', [$input, $input], [$token, $token]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([200, 409], $statuses, json_encode($results));
        $this->assertDatabaseCount('billing_checkouts', 1);
        $this->assertDatabaseCount('billing_customers', 1);
    }

}
