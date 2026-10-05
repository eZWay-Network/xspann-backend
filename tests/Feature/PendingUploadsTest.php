<?php

namespace Tests\Feature;

use App\Jobs\PruneUploads;
use App\Models\Audio;
use App\Services\{ChunkUploads, PendingUploads};
use Tests\TestCase;

class PendingUploadsTest extends TestCase
{
    private function upload(string $path, bool $expired = true): void
    {
        storage('public')->put($path, 'uploaded');
        PendingUploads::track(1, $path);
        if ($expired) {
            query('pending_uploads')->where('path', $path)->update(['created_at' => date('Y-m-d H:i:s', time() - 49 * 3600)]);
        }
    }

    public function testCleanupDeletesOnlyExpiredUnreferencedUploads(): void
    {
        $user = $this->makeUser();
        foreach (['avatars', 'videos', 'sounds', 'thumbnails'] as $kind) {
            $this->upload("$kind/1/abandoned.mp4");
        }
        $this->upload('videos/1/recent.mp4', false);
        $this->upload('avatars/1/profile.png');
        $this->upload('videos/1/queued.mp4');
        $this->upload('thumbnails/1/cover.jpg');
        $this->upload('sounds/1/preview.m4a');
        $this->upload('sounds/1/queued.m4a');
        $user->update(['avatar' => 'avatars/1/profile.png']);
        $this->makeVideo($user, [
            'storage_path' => 'videos/1/queued.mp4',
            'thumbnail_url' => 'thumbnails/1/cover.jpg',
            'sound_preview_url' => 'sounds/1/preview.m4a',
            'status' => 'processing',
        ]);
        Audio::create(['user_id' => 1, 'title' => 'Queued audio', 'storage_path' => 'sounds/1/queued.m4a']);

        (new PruneUploads())->handle();
        foreach (['avatars', 'videos', 'sounds', 'thumbnails'] as $kind) {
            $this->assertFalse(storage('public')->exists("$kind/1/abandoned.mp4"));
        }
        foreach (['videos/1/recent.mp4', 'avatars/1/profile.png', 'videos/1/queued.mp4', 'thumbnails/1/cover.jpg', 'sounds/1/preview.m4a', 'sounds/1/queued.m4a'] as $path) {
            $this->assertTrue(storage('public')->exists($path));
        }
        $this->assertSame(1, query('pending_uploads')->count());
        (new PruneUploads())->handle();
        $this->assertSame(1, query('pending_uploads')->count());
        $this->asUser($user)->postJson('/api/v1/videos', ['storage_path' => 'videos/1/abandoned.mp4'])
            ->assertStatus(422)->assertJsonValidationErrors(['storage_path']);
    }

    public function testAudioCreationIsSeparateAndEnforcesOwnershipAndExistence(): void
    {
        $user = $this->makeUser();
        $this->makeUser('bob');
        $this->upload('sounds/1/sound.wav');
        storage('public')->put('sounds/2/sound.wav', 'uploaded');
        $this->postJson('/api/v1/audios', ['title' => 'Sound', 'storage_path' => 'sounds/1/sound.wav'])->assertStatus(401);
        $this->asUser($user);
        foreach (['sounds/2/sound.wav', 'sounds/1/missing.wav', 'https://external.example/sound.wav', 'videos/1/video.mp4'] as $path) {
            $this->postJson('/api/v1/audios', ['title' => 'Sound', 'storage_path' => $path])->assertStatus(422);
        }
        $this->postJson('/api/v1/audios', ['storage_path' => 'sounds/1/sound.wav'])->assertStatus(422);
        $this->postJson('/api/v1/audios', ['title' => 'Sound', 'storage_path' => 'sounds/1/sound.wav', 'status' => 'ready', 'user_id' => 2])
            ->assertStatus(201)->assertJsonPath('data.status', 'processing')->assertJsonPath('data.audio_url', null)
            ->assertJsonPath('data.creator.id', 1);
        (new PruneUploads())->handle();
        $this->assertTrue(storage('public')->exists('sounds/1/sound.wav'));
        $this->assertSame(1, Audio::count());
    }

    public function testChunkCleanupUsesLastActivityAndIgnoresOtherDirectories(): void
    {
        $stale = temp_dir('upload-chunks/1/123e4567-e89b-42d3-a456-426614174000');
        $active = temp_dir('upload-chunks/1/123e4567-e89b-42d3-a456-426614174001');
        $other = temp_dir('upload-chunks/1/not-an-upload');
        foreach ([$stale, $active, $other] as $directory) {
            mkdir($directory, 0755, true);
            file_put_contents("$directory/manifest.json", '{}');
            file_put_contents("$directory/0.part", 'chunk');
        }
        touch("$stale/manifest.json", time() - 49 * 3600);
        touch("$other/manifest.json", time() - 49 * 3600);
        app(ChunkUploads::class)->prune(time() - 48 * 3600);
        $this->assertFalse(is_dir($stale));
        $this->assertTrue(is_dir($active));
        $this->assertTrue(is_dir($other));
    }

    public function testCleanupPagesThroughMoreThanOneBatch(): void
    {
        $this->makeUser();
        for ($index = 0; $index < 105; $index++) {
            PendingUploads::track(1, "videos/1/missing-$index.mp4");
        }
        query('pending_uploads')->where('user_id', 1)->update(['created_at' => date('Y-m-d H:i:s', time() - 49 * 3600)]);

        (new PruneUploads())->handle();

        $this->assertSame(0, query('pending_uploads')->count());
    }

    public function testS3CleanupPreservesReferencesAndRetriesFailedDeletes(): void
    {
        $server = new \Tests\Support\HttpServer(dirname(__DIR__) . '/Fixtures/s3-router.php', $this->storagePath, ['XSPANN_S3_TEST_ROOT' => $this->storagePath]);
        try {
            $this->useS3(['endpoint' => $server->url, 'use_path_style_endpoint' => true]);
            $user = $this->makeUser();
            foreach (['avatars/1/active.png', 'sounds/1/abandoned.wav'] as $path) {
                storage('s3')->put($path, 'uploaded');
                PendingUploads::track(1, $path);
            }
            $user->update(['avatar' => 'avatars/1/active.png']);
            query('pending_uploads')->where('user_id', 1)->update(['created_at' => date('Y-m-d H:i:s', time() - 49 * 3600)]);
            file_put_contents($this->storagePath . '/fail-delete', '1');
            $failed = false;
            try {
                (new PruneUploads())->handle();
            } catch (\Throwable) {
                $failed = true;
            }
            $this->assertTrue($failed);
            $this->assertDatabaseHas('pending_uploads', ['path' => 'sounds/1/abandoned.wav']);
            $this->assertTrue(storage('s3')->exists('sounds/1/abandoned.wav'));

            unlink($this->storagePath . '/fail-delete');
            (new PruneUploads())->handle();
            $this->assertFalse(storage('s3')->exists('sounds/1/abandoned.wav'));
            $this->assertTrue(storage('s3')->exists('avatars/1/active.png'));
            $this->assertSame(0, query('pending_uploads')->count());
        } finally {
            $server->stop();
        }
    }

    public function testNeverUploadedSignedTargetAndDeletedAccountCanBePruned(): void
    {
        $user = $this->makeUser();
        $this->upload('videos/1/abandoned.mp4');
        PendingUploads::track(1, 'videos/1/never-uploaded.mp4');
        query('pending_uploads')->where('user_id', 1)->update(['created_at' => date('Y-m-d H:i:s', time() - 49 * 3600)]);
        $user->delete();
        (new PruneUploads())->handle();
        $this->assertSame(0, query('pending_uploads')->count());
        $this->assertFalse(storage('public')->exists('videos/1/abandoned.mp4'));
    }
}
