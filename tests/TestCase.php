<?php

namespace Tests;

use App\Models\{User, Video};
use Spark\Facades\Auth;
use Spark\Foundation\Application;

abstract class TestCase extends \Spark\Testing\ApplicationTestCase
{
    protected function testStorageDirectory(): string
    {
        return dirname(__DIR__) . '/storage/framework/testing';
    }

    protected function createApplication(): Application
    {
        $app = require dirname(__DIR__) . '/bootstrap/app.php';
        $app->mergeConfig(require __DIR__ . '/config.php');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.connections.sqlite.file');
        if (config('database.driver') !== 'sqlite' || ($database !== ':memory:' && !str_starts_with($database, $this->storagePath . '/'))) {
            throw new \LogicException('Tests must use an isolated SQLite database.');
        }

        foreach (glob(dirname(__DIR__) . '/database/migrations/migration_*.php') as $path) {
            (require $path)->up();
        }
    }

    protected function makeUser(string $username = 'alice'): User
    {
        return User::create([
            'username' => $username,
            'name' => ucfirst($username),
            'email' => "$username@example.com",
            'password' => 'password123',
            'email_verified_at' => now(),
        ])->refresh();
    }

    protected function makeVideo(User $user, array $attributes = []): Video
    {
        return Video::create([
            'user_id' => $user->id,
            'storage_path' => "videos/{$user->id}/sample.mp4",
            'caption' => 'Hello #world',
            'status' => Video::STATUS_PUBLISHED,
            ...$attributes,
        ])->refresh();
    }

    protected function token(User $user): string
    {
        Auth::login($user);

        return Auth::createToken();
    }

    protected function asUser(User $user): static
    {
        return $this->withToken($this->token($user));
    }

    protected function useS3(array $config = []): void
    {
        $this->app->mergeConfig([
            'storage' => [
                'default' => 's3',
                'disks' => [
                    's3' => [
                        'driver' => 's3',
                        'temporary_urls' => false,
                        'key' => 'test-key',
                        'secret' => 'test-secret',
                        'region' => 'us-east-1',
                        'bucket' => 'example',
                        'endpoint' => null,
                        'url' => 'https://cdn.example.com',
                        'token' => null,
                        'acl' => null,
                        'use_path_style_endpoint' => false,
                        ...$config,
                    ]
                ],
            ]
        ]);
    }

    protected function fakeVideoTools(string $duration = '12.25'): void
    {
        $probe = $this->storagePath . '/ffprobe';
        $ffmpeg = $this->storagePath . '/ffmpeg';
        file_put_contents($probe, "#!/usr/bin/env php\n<?php echo " . var_export($duration, true) . ";\n");
        file_put_contents($ffmpeg, "#!/usr/bin/env php\n<?php file_put_contents(end(\$argv), 'fixture-thumbnail'); file_put_contents(__DIR__ . '/arguments.json', json_encode(\$argv));\n");
        chmod($probe, 0700);
        chmod($ffmpeg, 0700);
        $this->app->mergeConfig(['app' => ['ffprobe' => $probe, 'ffmpeg' => $ffmpeg]]);
    }
}
