<?php

namespace Tests\Feature;

use App\Jobs\DeleteVideo;
use App\Models\{Comment, Like, Report, Save, Share, Video, VideoView};
use Spark\Queue\Queue;
use Tests\Support\HttpServer;
use Tests\TestCase;

class VideoDeletionTest extends TestCase
{
    public function testDeletionHidesImmediatelyAndRemovesMediaAndRelatedRows(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser('other');
        $video = $this->makeVideo($owner, ['thumbnail_url' => 'thumbnails/1/cover.jpg', 'sound_preview_url' => 'sounds/1/audio.mp3']);
        foreach ([$video->storage_path, $video->thumbnail_url, $video->sound_preview_url] as $path) {
            storage('public')->put($path, 'file');
        }
        $comment = Comment::create(['user_id' => $other->id, 'video_id' => $video->id, 'body' => 'Hi']);
        query('comments_reacts')->insert(['user_id' => $owner->id, 'comment_id' => $comment->id]);
        foreach ([Like::class, Save::class, Share::class, VideoView::class] as $model) {
            $model::create(['user_id' => $other->id, 'video_id' => $video->id]);
        }
        Report::create(['user_id' => $other->id, 'video_id' => $video->id, 'reason' => 'Test']);
        $this->asUser($other)->deleteJson('/api/v1/videos/' . $video->id)->assertStatus(403);
        $this->asUser($owner)->deleteJson('/api/v1/videos/' . $video->id)->assertOk();
        $this->patchJson('/api/v1/videos/' . $video->id, ['thumbnail_url' => null])->assertStatus(404);
        $this->getJson('/api/v1/videos/' . $video->id)->assertStatus(404);
        $this->getJson('/api/v1/feed')->assertOk()->assertJsonCount(0, 'data');
        app(Queue::class)->work(once: true, timeout: 5, sleep: 0);
        foreach (['videos', 'comments', 'comments_reacts', 'likes', 'saves', 'shares', 'video_views', 'reports'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        foreach ([$video->storage_path, $video->thumbnail_url, $video->sound_preview_url] as $path) {
            $this->assertFalse(storage('public')->exists($path));
        }
        (new DeleteVideo((int) $video->id))->handle();
    }

    public function testCleanupPreservesSharedExternalAndOtherOwnersFiles(): void
    {
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner, ['thumbnail_url' => 'thumbnails/2/other.jpg', 'sound_preview_url' => 'https://music.example.com/audio.mp3', 'status' => 'deleted']);
        $this->makeVideo($owner); // Same video key is still referenced.
        storage('public')->put($video->storage_path, 'shared');
        storage('public')->put('thumbnails/2/other.jpg', 'other');
        (new DeleteVideo((int) $video->id))->handle();
        $this->assertNull(Video::find($video->id));
        $this->assertTrue(storage('public')->exists($video->storage_path));
        $this->assertTrue(storage('public')->exists('thumbnails/2/other.jpg'));
    }

    public function testS3CleanupCanRetryAfterStorageFailure(): void
    {
        $server = new HttpServer(dirname(__DIR__) . '/Fixtures/s3-router.php', $this->storagePath, ['XSPANN_S3_TEST_ROOT' => $this->storagePath]);
        try {
            $this->useS3(['endpoint' => $server->url, 'use_path_style_endpoint' => true]);
            $video = $this->makeVideo($this->makeUser(), ['thumbnail_url' => 'thumbnails/1/cover.jpg', 'status' => 'deleted']);
            storage('s3')->put('videos/1/sample.mp4', 'video');
            storage('s3')->put('thumbnails/1/cover.jpg', 'image');
            file_put_contents($this->storagePath . '/fail-delete', '');
            $this->assertThrows(\RuntimeException::class, fn() => (new DeleteVideo((int) $video->id))->handle());
            $this->assertSame('deleted', $video->refresh()->status);
            unlink($this->storagePath . '/fail-delete');
            (new DeleteVideo((int) $video->id))->handle();
            $this->assertNull(Video::find($video->id));
            $this->assertFalse(is_file($this->storagePath . '/videos/1/sample.mp4'));
            $this->assertFalse(is_file($this->storagePath . '/thumbnails/1/cover.jpg'));
        } finally {
            $server->stop();
        }
    }
}
