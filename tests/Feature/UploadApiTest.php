<?php

namespace Tests\Feature;

use Spark\Foundation\Application;
use Tests\TestCase;
use Tests\Support\HttpServer;

class UploadApiTest extends TestCase
{
    private ?HttpServer $server = null;
    private string $baseUrl;
    private string $bearer = '';

    protected function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->mergeConfig(['database' => ['connections' => ['sqlite' => ['file' => $this->storagePath . '/database.sqlite']]]]);
        return $app;
    }

    protected function tearDown(): void
    {
        $this->server?->stop();
        parent::tearDown();
    }

    private function startServer(): void
    {
        $config = [];
        foreach (['app', 'auth', 'database', 'session', 'cache', 'queue', 'storage', 'cors'] as $key) {
            $config[$key] = config($key);
        }
        $documentRoot = $this->storagePath . '/public';
        mkdir($documentRoot);
        symlink(config('storage.disks.public.root'), $documentRoot . '/uploads');
        $path = $this->storagePath . '/http-config.json';
        $this->server = new HttpServer(
            dirname(__DIR__) . '/Fixtures/http-router.php',
            $this->storagePath,
            ['XSPANN_HTTP_TEST_CONFIG' => $path],
            $documentRoot,
        );
        $this->baseUrl = $this->server->url;
        $config['app']['url'] = $this->baseUrl;
        $config['storage']['disks']['public']['url'] = $this->baseUrl . '/uploads';
        file_put_contents($path, json_encode($config, JSON_THROW_ON_ERROR));
    }

    private function http(string $path, array $data, int $expected = 200, bool $multipart = false, string $method = 'POST'): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($this->bearer) {
            $headers['Authorization'] = 'Bearer ' . $this->bearer;
        }
        if (!$multipart) {
            $headers['Content-Type'] = 'application/json';
        }
        return $this->server->request($method, '/api/v1' . $path, $multipart ? $data : json_encode($data), $headers)
            ->assertStatus($expected)->json();
    }

    private function loginHttp(): void
    {
        $this->makeUser();
        $this->startServer();
        $this->bearer = $this->http('/auth/login', ['email' => 'alice@example.com', 'password' => 'password123'])['data']['token'];
    }

    private function videoFile(): string
    {
        // Container header fixture for MIME/upload transport tests, not a decodable movie.
        $path = $this->storagePath . '/sample.mp4';
        file_put_contents($path, pack('N', 24) . 'ftypmp42' . pack('N', 0) . 'mp42isom' . str_repeat("\0", 128));
        return $path;
    }

    public function testCorsHeadersReachBrowserOnHttpErrors(): void
    {
        $this->startServer();
        $headers = ['Accept' => 'application/json', 'Origin' => 'http://localhost:3000'];

        foreach ([
            ['POST', '/api/v1/auth/register', 422],
            ['GET', '/api/v1/videos/98765', 404],
            ['GET', '/api/v1/auth/me', 401],
        ] as [$method, $path, $status]) {
            $this->server->request($method, $path, headers: $headers)
                ->assertStatus($status)
                ->assertHeader('Access-Control-Allow-Origin', $headers['Origin']);
        }
    }

    public function testRealMultipartVideoAudioAndAvatarUploads(): void
    {
        $this->loginHttp();
        $file = $this->videoFile();
        $data = $this->http('/uploads/videos/local', ['file' => new \CURLFile($file, 'video/mp4', 'clip.mp4')], 201, true)['data'];
        $this->assertSame(file_get_contents($file), storage('public')->get($data['storage_path']));
        $this->assertTrue(str_starts_with($data['storage_path'], 'videos/1/'));
        $this->http('/uploads/videos/local', ['file' => new \CURLFile($file, 'video/mp4', 'clip.txt')], 422, true);
        $wav = $this->storagePath . '/sound.wav';
        file_put_contents($wav, 'RIFF' . pack('V', 38) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', 2) . "\0\0");
        $audio = $this->http('/uploads/sounds/local', ['file' => new \CURLFile($wav, 'audio/wav', 'sound.wav')], 201, true)['data'];
        $this->assertTrue(storage('public')->exists($audio['storage_path']));
        $png = $this->storagePath . '/avatar.png';
        $image = imagecreatetruecolor(8, 8);
        imagepng($image, $png);
        imagedestroy($image);
        $avatar = $this->http('/uploads/avatar', ['file' => new \CURLFile($png, 'image/png', 'avatar.png')], 201, true)['data'];
        $this->assertTrue(storage('public')->exists($avatar['storage_path']));
        $this->http('/uploads/videos/local', ['file' => new \CURLFile($png, 'video/mp4', 'fake.mp4')], 422, true);
    }

    public function testUploadCreateProcessAndReadThroughHttp(): void
    {
        $this->loginHttp();
        $upload = $this->http('/uploads/videos/local', ['file' => new \CURLFile($this->videoFile(), 'video/mp4', 'clip.mp4')], 201, true)['data'];
        $video = $this->http('/videos', ['storage_path' => $upload['storage_path'], 'caption' => 'End to end #test'], 201)['data'];
        $this->assertSame('processing', $video['status']);
        $this->assertSame([], $this->http('/feed', [], method: 'GET')['data']);
        $this->assertSame('processing', $this->http('/me/videos', [], method: 'GET')['data'][0]['status']);

        $this->fakeVideoTools('8.5');
        app(\Spark\Queue\Queue::class)->work(once: true, timeout: 5, sleep: 0, queue: 'default');

        $published = $this->http('/videos/' . $video['id'], [], method: 'GET')['data'];
        $this->assertSame('published', $published['status']);
        $stored = \App\Models\Video::find($video['id']);
        $this->assertSame($upload['storage_path'], $stored->storage_path);
        $this->assertTrue(str_starts_with($stored->thumbnail_url, 'thumbnails/1/'));
        $this->assertSame(9, $published['duration']);
        $this->assertSame(['#test'], $published['tags']);
        $this->assertTrue(str_contains($published['thumbnail_url'], '/uploads/thumbnails/1/'));
        $this->assertSame($video['id'], $this->http('/feed', [], method: 'GET')['data'][0]['id']);
    }

    public function testRealChunkedUploadMissingChunksAndManifestIntegrity(): void
    {
        $this->loginHttp();
        $file = $this->videoFile();
        $bytes = file_get_contents($file);
        $parts = str_split($bytes, (int) ceil(strlen($bytes) / 2));
        $data = ['upload_id' => '123e4567-e89b-42d3-a456-426614174000', 'total_chunks' => 2, 'filename' => 'clip.mp4', 'content_type' => 'video/mp4', 'total_size' => strlen($bytes)];
        foreach ($parts as $index => $part) {
            $path = $this->storagePath . "/$index.part";
            file_put_contents($path, $part);
            if ($index === 1) {
                $this->http('/uploads/videos/complete', $data, 422);
                $this->assertFalse(is_file($this->storagePath . '/temp/upload-chunks/1/' . $data['upload_id'] . '/assembled.tmp'));
            }
            $this->http('/uploads/videos/chunk', [...$data, 'chunk_index' => sprintf('%02d', $index), 'chunk' => new \CURLFile($path)], 201, true);
        }
        $this->http('/uploads/videos/complete', [...$data, 'total_size' => strlen($bytes) + 1], 422);
        $response = $this->http('/uploads/videos/complete', $data, 201)['data'];
        $this->assertSame($bytes, storage('public')->get($response['storage_path']));
        $this->assertFalse(is_dir($this->storagePath . '/temp/upload-chunks/1/' . $data['upload_id']));
        $this->http('/uploads/videos/complete', $data, 422);
    }

    public function testSignedFallbackAndUploadValidation(): void
    {
        $this->asUser($this->makeUser());
        $this->postJson('/api/v1/uploads/videos/signed-url', ['filename' => 'video.mp4', 'content_type' => 'video/mp4'])->assertOk()->assertJsonPath('data.upload_method', 'multipart');
        $this->postJson('/api/v1/uploads/videos/signed-url', ['filename' => 'bad', 'content_type' => 'text/html'])->assertStatus(422);
        foreach (['videos/local', 'sounds/local', 'avatar'] as $path) {
            $this->postJson('/api/v1/uploads/' . $path)->assertStatus(422)->assertJsonValidationErrors(['file']);
        }
        $this->postJson('/api/v1/uploads/videos/chunk', ['upload_id' => '../bad', 'total_chunks' => 1.2])->assertStatus(422);
    }

    public function testLocalMediaIsServedFromThePublicDisk(): void
    {
        storage('public')->put('videos/1/file.mp4', '0123456789');
        $this->startServer();
        $response = $this->server->request('GET', '/uploads/videos/1/file.mp4')
            ->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->assertSame('0123456789', $response->content());
    }

    public function testConfiguredSpacesReturnsSignedPutContractAndMissingCredentialsFallBack(): void
    {
        $this->asUser($this->makeUser());
        $this->app->mergeConfig([
            'storage' => [
                'default' => 'spaces',
                'video_upload_mode' => 'signed',
                'disks' => [
                    'spaces' => [
                        'driver' => 's3',
                        'key' => 'test-key',
                        'secret' => 'test-secret',
                        'region' => 'nyc3',
                        'bucket' => 'example',
                        'endpoint' => 'https://nyc3.digitaloceanspaces.com',
                    ]
                ]
            ]
        ]);
        $response = $this->postJson('/api/v1/uploads/videos/signed-url', ['filename' => 'clip.mp4', 'content_type' => 'video/mp4'])
            ->assertOk()->assertJsonPath('data.upload_method', 'signed_url')->assertJsonPath('data.headers.Content-Type', 'video/mp4');
        $this->assertTrue(str_starts_with($response->json('data.storage_path'), 'videos/1/'));
        $this->assertTrue(str_starts_with($response->json('data.upload_url'), 'https://example.nyc3.digitaloceanspaces.com/'));
        $this->app->mergeConfig(['storage' => ['disks' => ['spaces' => ['secret' => null]]]]);
        $this->postJson('/api/v1/uploads/videos/signed-url', ['filename' => 'clip.mp4', 'content_type' => 'video/mp4'])
            ->assertOk()->assertJsonPath('data.upload_method', 'multipart');
    }

    public function testS3MultipartVideoAvatarAndAudioStoreFullPublicUrls(): void
    {
        $cloud = new HttpServer(dirname(__DIR__) . '/Fixtures/s3-router.php', $this->storagePath, ['XSPANN_S3_TEST_ROOT' => $this->storagePath]);
        try {
            $this->useS3(['endpoint' => $cloud->url, 'use_path_style_endpoint' => true]);
            $this->loginHttp();
            $file = $this->videoFile();
            $plan = $this->http('/uploads/videos/signed-url', ['filename' => 'clip.mp4', 'content_type' => 'video/mp4'])['data'];
            $this->assertSame('multipart', $plan['upload_method']);
            $this->assertSame($this->baseUrl . '/api/v1/uploads/videos/local', $plan['upload_url']);
            $upload = $this->server->request(
                'POST',
                parse_url($plan['upload_url'], PHP_URL_PATH),
                [$plan['field_name'] => new \CURLFile($file, 'video/mp4', 'clip.mp4')],
                ['Accept' => 'application/json', 'Authorization' => 'Bearer ' . $this->bearer],
            )->assertStatus(201)->json('data');
            $this->assertSame(file_get_contents($file), file_get_contents($this->storagePath . '/' . $upload['storage_path']));
            $this->assertFalse(storage('public')->exists($upload['storage_path']));
            $this->assertSame('https://cdn.example.com/' . $upload['storage_path'], $upload['video_url']);
            $video = $this->http('/videos', ['storage_path' => $upload['storage_path']], 201)['data'];
            $this->assertDatabaseHas('videos', ['id' => $video['id'], 'storage_path' => $upload['video_url']]);

            $this->fakeVideoTools();
            app(\Spark\Queue\Queue::class)->work(once: true, timeout: 5, sleep: 0);
            $published = $this->http('/videos/' . $video['id'], [], method: 'GET')['data'];
            $this->assertSame('published', $published['status']);
            $this->assertSame(13, $published['duration']);
            $this->assertTrue(str_starts_with($published['thumbnail_url'], 'https://cdn.example.com/thumbnails/1/'));
            $this->assertSame([], glob($this->storagePath . '/temp/video-processing/*'));

            $png = $this->storagePath . '/avatar.png';
            $image = imagecreatetruecolor(8, 8);
            imagepng($image, $png);
            $avatar = $this->http('/uploads/avatar', ['file' => new \CURLFile($png, 'image/png', 'avatar.png')], 201, true)['data'];
            $this->http('/auth/profile', ['avatar' => $avatar['avatar_url']], method: 'PUT');
            $this->assertDatabaseHas('users', ['id' => 1, 'avatar' => 'https://cdn.example.com/' . $avatar['storage_path']]);

            $wav = $this->storagePath . '/audio.wav';
            file_put_contents($wav, 'RIFF' . pack('V', 38) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16) . 'data' . pack('V', 2) . "\0\0");
            $audio = $this->http('/uploads/sounds/local', ['file' => new \CURLFile($wav, 'audio/wav', 'audio.wav')], 201, true)['data'];
            $this->http('/videos/' . $video['id'], ['sound_preview_url' => $audio['audio_url']], method: 'PATCH');
            $this->assertDatabaseHas('videos', ['id' => $video['id'], 'sound_preview_url' => 'https://cdn.example.com/' . $audio['storage_path']]);
            $this->assertSame(file_get_contents($wav), file_get_contents($this->storagePath . '/' . $audio['storage_path']));
        } finally {
            $cloud->stop();
        }
    }

    public function testS3ChunkCompletionCanRetryAfterStorageFailure(): void
    {
        $cloud = new HttpServer(dirname(__DIR__) . '/Fixtures/s3-router.php', $this->storagePath, ['XSPANN_S3_TEST_ROOT' => $this->storagePath]);
        try {
            $this->useS3(['endpoint' => $cloud->url, 'use_path_style_endpoint' => true]);
            $this->loginHttp();
            $file = $this->videoFile();
            $data = ['upload_id' => '123e4567-e89b-42d3-a456-426614174000', 'total_chunks' => 1, 'filename' => 'clip.mp4', 'content_type' => 'video/mp4', 'total_size' => filesize($file)];
            $this->http('/uploads/videos/chunk', [...$data, 'chunk_index' => 0, 'chunk' => new \CURLFile($file)], 201, true);
            $directory = $this->storagePath . '/temp/upload-chunks/1/' . $data['upload_id'];

            file_put_contents($this->storagePath . '/fail-put', '');
            $this->http('/uploads/videos/local', ['file' => new \CURLFile($file, 'video/mp4', 'clip.mp4')], 500, true);
            $this->http('/uploads/videos/complete', $data, 500);
            $this->assertTrue(is_file($directory . '/0.part'));
            $this->assertFalse(is_file($directory . '/assembled.tmp'));
            $this->assertSame([], glob($this->storagePath . '/videos/1/*') ?: []);

            unlink($this->storagePath . '/fail-put');
            $upload = $this->http('/uploads/videos/complete', $data, 201)['data'];
            $this->assertSame(file_get_contents($file), file_get_contents($this->storagePath . '/' . $upload['storage_path']));
            $this->assertSame('https://cdn.example.com/' . $upload['storage_path'], $upload['video_url']);
            $this->assertFalse(storage('public')->exists($upload['storage_path']));
            $this->assertFalse(is_dir($directory));
        } finally {
            $cloud->stop();
        }
    }

    public function testPrivateS3SignedAndBackendUploadsCanBeProcessedAndViewed(): void
    {
        $cloud = new HttpServer(dirname(__DIR__) . '/Fixtures/s3-router.php', $this->storagePath, ['XSPANN_S3_TEST_ROOT' => $this->storagePath]);
        try {
            $this->useS3(['endpoint' => $cloud->url, 'use_path_style_endpoint' => true, 'temporary_urls' => true]);
            $this->app->mergeConfig(['storage' => ['video_upload_mode' => 'signed']]);
            $this->loginHttp();
            $file = $this->videoFile();
            $this->fakeVideoTools();
            $signed = $this->http('/uploads/videos/signed-url', ['filename' => 'clip.mp4', 'content_type' => 'video/mp4'])['data'];
            $this->assertSame('signed_url', $signed['upload_method']);
            $cloud->request('PUT', substr($signed['upload_url'], strlen($cloud->url)), file_get_contents($file), $signed['headers'])->assertOk();
            $backend = $this->http('/uploads/videos/local', ['file' => new \CURLFile($file, 'video/mp4', 'clip.mp4')], 201, true)['data'];

            foreach ([$signed, $backend] as $upload) {
                $video = $this->http('/videos', ['storage_path' => $upload['storage_path']], 201)['data'];
                $this->assertDatabaseHas('videos', ['id' => $video['id'], 'storage_path' => 'https://cdn.example.com/' . $upload['storage_path']]);
                app(\Spark\Queue\Queue::class)->work(once: true, timeout: 5, sleep: 0);
                $published = $this->http('/videos/' . $video['id'], [], method: 'GET')['data'];
                $this->assertSame('published', $published['status']);
                foreach ([$published['video_url'], $published['thumbnail_url']] as $url) {
                    $this->assertTrue(str_contains($url, 'X-Amz-Signature='));
                    $cloud->request('GET', substr($url, strlen($cloud->url)))->assertOk();
                }
                $this->assertFalse(storage('public')->exists($upload['storage_path']));
                $this->http('/videos/' . $video['id'], [], method: 'DELETE');
                app(\Spark\Queue\Queue::class)->work(once: true, timeout: 5, sleep: 0);
                $this->assertFalse(storage('s3')->exists($upload['storage_path']));
            }
        } finally {
            $cloud->stop();
        }
    }

}
