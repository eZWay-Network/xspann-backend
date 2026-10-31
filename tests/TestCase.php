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
        // Queue tests dispatch their own jobs; recurring production jobs are registered separately.
        $app->withQueue();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.connections.sqlite.file');
        if (config('database.default') !== 'sqlite' || ($database !== ':memory:' && !str_starts_with($database, $this->storagePath . '/'))) {
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
            'date_of_birth' => '1990-01-01',
            'onboarded_at' => now(),
        ])->refresh();
    }

    protected function makeVideo(User $user, array $attributes = []): Video
    {
        return Video::create([
            'user_id' => $user->id,
            'storage_path' => "videos/{$user->id}/sample.mp4",
            'caption' => 'Hello #world',
            'reuse_content' => true,
            'rights_version' => config('policies.rights_version'),
            'rights_confirmed_at' => now(),
            'status' => Video::STATUS_PUBLISHED,
            'processing_state' => ($attributes['status'] ?? 'published') === 'published' ? 'ready' : (($attributes['status'] ?? '') === 'processing' ? 'processing' : 'failed'),
            'publication_state' => ($attributes['status'] ?? 'published') === 'published' ? 'published' : 'draft',
            ...$attributes,
        ])->refresh();
    }

    protected function videoRights(): array
    {
        return ['rights_confirmed' => true, 'rights_version' => config('policies.rights_version'), 'reuse_content' => true];
    }

    protected function audioRights(): array
    {
        return ['rights_confirmed' => true, 'rights_version' => config('policies.rights_version'), 'reuse_allowed' => true];
    }

    protected function enrollment(): array
    {
        return ['date_of_birth' => '1990-01-01', 'accepted_policies' => array_map(fn($policy) => $policy['version'], array_filter(config('policies.documents'), fn($policy) => $policy['required']))];
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

    protected function mediaKey(string $value): string
    {
        $location = \App\Services\StorageService::location($value);
        if (!$location) {
            throw new \InvalidArgumentException('Expected a managed media reference.');
        }

        return $location['key'];
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
        $metadata = json_encode(['format' => ['duration' => $duration], 'streams' => [['codec_type' => 'video'], ['codec_type' => 'audio']]]);
        file_put_contents($probe, "#!/usr/bin/env php\n<?php echo in_array('json', \$argv, true) ? " . var_export($metadata, true) . " : " . var_export($duration, true) . ";\n");
        file_put_contents($ffmpeg, "#!/usr/bin/env php\n<?php file_put_contents(end(\$argv), 'fixture-thumbnail'); file_put_contents(__DIR__ . '/arguments.json', json_encode(\$argv));\n");
        chmod($probe, 0700);
        chmod($ffmpeg, 0700);
        if (PHP_OS_FAMILY === 'Windows') {
            $probeBat = str_replace('/', '\\', $probe . '.bat');
            $ffmpegBat = str_replace('/', '\\', $ffmpeg . '.bat');
            file_put_contents($probeBat, '@echo off' . "\r\n" . '"' . PHP_BINARY . '" "' . str_replace('/', '\\', $probe) . '" %*');
            file_put_contents($ffmpegBat, '@echo off' . "\r\n" . '"' . PHP_BINARY . '" "' . str_replace('/', '\\', $ffmpeg) . '" %*');
            $this->app->mergeConfig(['app' => ['ffprobe' => $probeBat, 'ffmpeg' => $ffmpegBat]]);
        } else {
            $this->app->mergeConfig(['app' => ['ffprobe' => $probe, 'ffmpeg' => $ffmpeg]]);
        }
    }
}
