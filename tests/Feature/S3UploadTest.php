<?php

namespace Tests\Feature;

use App\Models\Video;
use App\Services\VideoMetadataExtractor;
use Spark\Queue\Queue;
use Tests\Support\HttpServer;
use Tests\TestCase;

class S3UploadTest extends TestCase
{
    public function testBackendUploadModeKeepsTheSelectedDisk(): void
    {
        $this->asUser($this->makeUser());
        $this->useS3();
        $payload = ['filename' => 'clip.mp4', 'content_type' => 'video/mp4'];

        $this->postJson('/api/v1/uploads/videos/signed-url', $payload)->assertOk()
            ->assertJsonPath('data.upload_method', 'multipart')
            ->assertJsonPath('data.upload_url', (string) url('api/v1/uploads/videos/local'));
        $this->assertSame('s3', \App\Services\StorageService::diskName());

        $this->app->mergeConfig(['storage' => ['default' => 'public', 'video_upload_mode' => 'signed']]);
        $this->postJson('/api/v1/uploads/videos/signed-url', $payload)->assertOk()
            ->assertJsonPath('data.upload_method', 'multipart');
    }

    public function testSignedUploadUsesNativeAwsOriginAndReturnsRequiredHeaders(): void
    {
        $this->asUser($this->makeUser());
        $this->useS3();
        $this->app->mergeConfig(['storage' => ['video_upload_mode' => 'signed']]);
        $response = $this->postJson('/api/v1/uploads/videos/signed-url', ['filename' => 'clip.mp4', 'content_type' => 'video/mp4'])
            ->assertOk()->assertJsonPath('data.upload_method', 'signed_url');
        $data = $response->json('data');
        $this->assertSame('example.s3.us-east-1.amazonaws.com', parse_url($data['upload_url'], PHP_URL_HOST));
        $this->assertSame('https://cdn.example.com/' . $data['storage_path'], $data['video_url']);
        $this->assertSame(['Content-Type' => 'video/mp4'], $data['headers']);
        parse_str(parse_url($data['upload_url'], PHP_URL_QUERY), $query);
        $this->assertSame('900', $query['X-Amz-Expires']);
        $this->assertSame('content-type;host', $query['X-Amz-SignedHeaders']);

        $this->useS3(['endpoint' => 'http://127.0.0.1:9000', 'use_path_style_endpoint' => true, 'token' => 'temporary-token', 'acl' => 'public-read']);
        $data = $this->postJson('/api/v1/uploads/videos/signed-url', ['filename' => 'clip.webm', 'content_type' => 'video/webm'])->assertOk()->json('data');
        $this->assertSame('/example/' . $data['storage_path'], parse_url($data['upload_url'], PHP_URL_PATH));
        $this->assertSame(['Content-Type' => 'video/webm', 'x-amz-acl' => 'public-read'], $data['headers']);
        parse_str(parse_url($data['upload_url'], PHP_URL_QUERY), $query);
        $this->assertSame('temporary-token', $query['X-Amz-Security-Token']);
        $this->assertSame('content-type;host;x-amz-acl', $query['X-Amz-SignedHeaders']);
    }

    public function testDirectUploadProcessingAndCoverUrlsUseTheS3Disk(): void
    {
        $server = new HttpServer(dirname(__DIR__) . '/Fixtures/s3-router.php', $this->storagePath, ['XSPANN_S3_TEST_ROOT' => $this->storagePath]);
        try {
            $this->useS3(['endpoint' => $server->url, 'use_path_style_endpoint' => true]);
            $this->app->mergeConfig(['storage' => ['video_upload_mode' => 'signed']]);
            $this->asUser($this->makeUser());
            $this->fakeVideoTools();
            $upload = $this->postJson('/api/v1/uploads/videos/signed-url', ['filename' => 'clip.mp4', 'content_type' => 'video/mp4'])->assertOk()->json('data');
            $bytes = pack('N', 24) . 'ftypmp42' . pack('N', 0) . 'mp42isom' . str_repeat("\0", 128);
            $server->request('PUT', substr($upload['upload_url'], strlen($server->url)), $bytes, $upload['headers'])->assertOk();
            $this->assertSame($bytes, file_get_contents($this->storagePath . '/' . $upload['storage_path']));
            $id = $this->postJson('/api/v1/videos', ['storage_path' => $upload['storage_path']])->assertStatus(201)->json('data.id');
            app(Queue::class)->work(once: true, timeout: 5, sleep: 0);
            $video = Video::find($id);
            $this->assertSame('published', $video->status);
            $this->assertSame($upload['video_url'], $video->storage_path);
            $this->assertSame(13, $video->duration);
            $this->assertTrue(str_starts_with($video->thumbnail_url, 'https://cdn.example.com/thumbnails/1/'));
            $this->assertSame('fixture-thumbnail', file_get_contents($this->storagePath . '/' . substr($video->thumbnail_url, strlen('https://cdn.example.com/'))));
            $this->getJson('/api/v1/feed')->assertOk()
                ->assertJsonPath('data.0.video_url', $upload['video_url'])
                ->assertJsonPath('data.0.thumbnail_url', $video->thumbnail_url)
                ->assertJsonPath('data.0.user.cover_url', $video->thumbnail_url);
            $this->assertSame([], glob($this->storagePath . '/temp/video-processing/*'));
            $requests = file_get_contents($this->storagePath . '/requests.log');
            $this->assertTrue(str_contains($requests, 'GET ' . $upload['storage_path']));

            $video->storage_path = 'https://unrelated.example.com/video.mp4';
            $this->assertSame([], (new VideoMetadataExtractor())->extract($video));
            $this->assertSame($requests, file_get_contents($this->storagePath . '/requests.log'));
        } finally {
            $server->stop();
        }
    }

    public function testMissingInvalidAndOversizedS3UploadsCannotPublish(): void
    {
        $server = new HttpServer(dirname(__DIR__) . '/Fixtures/s3-router.php', $this->storagePath, ['XSPANN_S3_TEST_ROOT' => $this->storagePath]);
        try {
            $this->useS3(['endpoint' => $server->url, 'use_path_style_endpoint' => true]);
            $this->fakeVideoTools();
            $user = $this->makeUser();
            mkdir($this->storagePath . '/videos/1', 0700, true);
            file_put_contents($this->storagePath . '/videos/1/invalid.mp4', 'not a video');
            $large = fopen($this->storagePath . '/videos/1/large.mp4', 'wb');
            ftruncate($large, \App\Services\ChunkUploads::MAX_BYTES + 1);
            fclose($large);

            foreach (['missing', 'invalid', 'large'] as $name) {
                $video = $this->makeVideo($user, ['storage_path' => "videos/1/$name.mp4", 'status' => 'processing']);
                \App\Jobs\ProcessVideo::dispatch((int) $video->id)->send();
                app(Queue::class)->work(once: true, timeout: 5, sleep: 0);
                $this->assertSame('failed', $video->refresh()->status);
                $this->assertSame([], glob($this->storagePath . '/temp/video-processing/*') ?: []);
            }
            $this->assertFalse(str_contains(file_get_contents($this->storagePath . '/requests.log'), 'GET videos/1/large.mp4'));
            $this->getJson('/api/v1/feed')->assertOk()->assertJsonPath('data', []);
        } finally {
            $server->stop();
        }
    }
}
