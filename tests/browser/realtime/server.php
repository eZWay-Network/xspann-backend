<?php

use App\Services\{Conversations, Moderation};
use Tests\Support\{CommunicationFixtures, HttpServer, RelayServer};

require dirname(__DIR__, 3) . '/vendor/autoload.php';

/** Disposable loopback API, private Relay and the application's recurring publisher. */
final class RealtimeJourneyServer extends Tests\TestCase
{
    use CommunicationFixtures;

    protected function createApplication(): Spark\Foundation\Application
    {
        $app = parent::createApplication();
        $app->mergeConfig(['database' => ['connections' => ['sqlite' => ['file' => $this->storagePath . '/journey.sqlite']]]]);

        return $app;
    }

    public function serve(string $control): void
    {
        $this->setUp();
        try {
            $socket = stream_socket_server('tcp://127.0.0.1:0');
            $web = 'http://' . stream_socket_get_name($socket, false);
            fclose($socket);
            $settings = [
                'apps' => [
                    [
                        'app_id' => '1',
                        'key' => 'test-key',
                        'secret' => 'synthetic-only',
                        'allowed_origins' => [$web],
                        'client_events' => false,
                    ]
                ]
            ];
            $relay = new RelayServer($this->storagePath, ['relay' => $settings]);
            $apiConfig = $this->storagePath . '/api.json';
            $api = new HttpServer(dirname(__DIR__, 2) . '/Fixtures/staff-router.php', $this->storagePath, ['XPN_STAFF_TEST_CONFIG' => $apiConfig]);
            $users = [];
            foreach (['alice', 'bob', 'carol'] as $name) {
                $users[] = $this->makeUser($name);
            }
            // A same-origin fixture image lets Chrome verify the avatar actually decoded.
            $users[0]->update(['avatar' => $web . '/images/xpn-logo.png']);
            foreach ([[0, 1], [1, 2], [0, 2]] as [$a, $b]) {
                $this->mutual($users[$a], $users[$b]);
                Conversations::open($users[$a]->id, $users[$b]->id);
            }
            $config = require dirname(__DIR__, 2) . '/config.php';
            $config['app']['url'] = $api->url;
            $config['database'] = config('database');
            $config['relay'] = [
                ...$settings,
                'public' => ['host' => '127.0.0.1', 'port' => $relay->port, 'tls' => false],
                'client' => ['host' => '127.0.0.1', 'port' => $relay->port, 'scheme' => 'http', 'timeout' => 2],
            ];
            file_put_contents($apiConfig, json_encode($config, JSON_THROW_ON_ERROR));
            $worker = proc_open([PHP_BINARY, __DIR__ . '/worker.php', $apiConfig], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $control . '/worker.log', 'a'],
                2 => ['file', $control . '/worker.log', 'a'],
            ], $pipes);
            file_put_contents($control . '/ready.json', json_encode(['web' => $web, 'api' => $api->url]));
            $deadline = time() + 1800;
            while (time() < $deadline && !is_file($control . '/stop')) {
                if (is_file($control . '/restrict')) {
                    unlink($control . '/restrict');
                    $message = query('messages')->where('conversation_id', 1)->first();
                    $report = Moderation::report($users[1], ['message_id' => $message->id, 'reason' => 'Fixture review']);
                    Moderation::decide($report->id, $users[2], ['action' => 'restrict', 'version' => $report->version, 'reason' => 'Fixture restriction']);
                }
                if (is_file($control . '/restart')) {
                    unlink($control . '/restart');
                    $relay->restart();
                }
                usleep(100000);
            }
        } finally {
            if (isset($worker) && is_resource($worker)) {
                proc_terminate($worker);
                proc_close($worker);
            }
            if (isset($api))
                $api->stop();
            if (isset($relay))
                $relay->stop();
            $this->tearDown();
        }
    }
}

(new RealtimeJourneyServer())->serve($argv[1]);
