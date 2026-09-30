<?php

// Each feature test receives its own temporary directory from Spark\Testing\ApplicationTestCase.
$storage = env('TEST_STORAGE_PATH');
if (!$storage) {
    throw new LogicException('Use the application TestCase to boot feature tests.');
}

return [
    'app' => [
        'debug' => false,
        'key' => '601b3ae753587af241e21d5fa2c04b73',
        'timezone' => 'UTC',
        'url' => 'http://localhost:8080',
        'storage_dir' => $storage,
        'temp_dir' => "$storage/temp",
        'upload_dir' => "$storage/uploads",
        'ffprobe' => '/usr/bin/false',
        'ffmpeg' => '/usr/bin/false',
        'frontend_url' => 'http://localhost:3000',
        'google_client_id' => null,
    ],
    'database' => [
        'driver' => 'sqlite',
        'connections' => ['sqlite' => ['file' => ':memory:']],
    ],
    'cache' => [
        'driver' => 'sqlite',
        'connections' => ['sqlite' => ['path' => "$storage/cache"]],
    ],
    'queue' => [
        'driver' => 'sqlite',
        'connections' => ['sqlite' => ['path' => "$storage/queue/jobs.db"]],
    ],
    'storage' => [
        'default' => 'public',
        'video_upload_mode' => 'local',
        'disks' => [
            'local' => ['driver' => 'local', 'root' => "$storage/app", 'visibility' => 'private'],
            'public' => ['driver' => 'local', 'root' => "$storage/uploads", 'url' => 'http://localhost:8080/uploads', 'visibility' => 'public'],
            's3' => ['key' => '', 'secret' => '', 'bucket' => '', 'endpoint' => null, 'url' => null, 'token' => null],
        ],
    ],
];
