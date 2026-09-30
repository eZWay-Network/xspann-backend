<?php

namespace Tests\Feature;

use App\Services\StorageService;
use Tests\Support\HttpServer;
use Tests\TestCase;

class PrivateMediaTest extends TestCase
{
    public function testPrivateS3UrlsAreSignedWhileStoredValuesStayStable(): void
    {
        $server = new HttpServer(dirname(__DIR__) . '/Fixtures/s3-router.php', $this->storagePath, ['XSPANN_S3_TEST_ROOT' => $this->storagePath]);
        try {
            $this->useS3(['endpoint' => $server->url, 'use_path_style_endpoint' => true, 'temporary_urls' => true]);
            $owner = $this->makeUser();
            $owner->update(['avatar' => 'avatars/1/avatar.png']);
            $video = $this->makeVideo($owner, ['thumbnail_url' => 'thumbnails/1/cover.jpg', 'sound_preview_url' => 'sounds/1/audio.mp3']);
            foreach (['videos/1/sample.mp4', 'thumbnails/1/cover.jpg', 'sounds/1/audio.mp3', 'avatars/1/avatar.png'] as $key) {
                storage('s3')->put($key, 'media');
            }
            $data = $this->getJson('/api/v1/videos/' . $video->id)->assertOk()->json('data');
            foreach ([$data['video_url'], $data['thumbnail_url'], $data['sound_preview_url'], $data['user']['avatar'], $data['user']['cover_url'], $data['user']['cover_video_url']] as $url) {
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $this->assertSame('3600', $query['X-Amz-Expires']);
                $this->assertArrayHasKey('X-Amz-Signature', $query);
                $server->request('GET', substr($url, strlen($server->url)))->assertOk();
            }
            $this->assertSame('https://cdn.example.com/videos/1/sample.mp4', $video->refresh()->storage_path);
            $this->assertSame('https://cdn.example.com/thumbnails/1/cover.jpg', StorageService::storedValue($data['thumbnail_url']));
            $this->asUser($owner)->patchJson('/api/v1/videos/' . $video->id, ['thumbnail_url' => $data['thumbnail_url']])->assertOk();
            $this->assertSame('https://cdn.example.com/thumbnails/1/cover.jpg', $video->refresh()->thumbnail_url);
            $this->asUser($this->makeUser('other'))->postJson('/api/v1/videos', ['storage_path' => $data['video_url']])->assertStatus(422);
            $this->postJson('/api/v1/videos', ['storage_path' => 'videos/2/../../secret.txt'])->assertStatus(422);
            $this->assertSame('https://cdn.example.com/private/secret.txt', StorageService::publicUrl('https://cdn.example.com/private/secret.txt'));
        } finally {
            $server->stop();
        }
    }

    public function testPrivateVideosDoNotExposeUrlsToOtherViewers(): void
    {
        $this->useS3(['temporary_urls' => true]);
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner, ['visibility' => 'private']);
        $this->getJson('/api/v1/videos/' . $video->id)->assertStatus(404);
        $this->asUser($this->makeUser('other'))->getJson('/api/v1/videos/' . $video->id)->assertStatus(404);
        $url = $this->asUser($owner)->getJson('/api/v1/videos/' . $video->id)->assertOk()->json('data.video_url');
        $this->assertTrue(str_contains($url, 'X-Amz-Signature='));
    }
}
