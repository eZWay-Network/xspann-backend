<?php

namespace Tests\Feature;

use App\Jobs\{DeleteUnusedMedia, DeleteVideo, ProcessVideo, PruneUploads};
use App\Models\Video;
use App\Services\{PendingUploads, StorageService};
use Tests\TestCase;

class MediaReferenceTest extends TestCase
{
    public function testReferencesKeepTheirDiskWhenDefaultsAndCdnUrlsChange(): void
    {
        $this->assertSame('public:videos/1/clip.mp4', StorageService::storedValue('videos/1/clip.mp4'));
        $this->useS3();

        foreach (['public', 's3', 'local'] as $disk) {
            $value = "$disk:videos/1/clip.mp4";
            $this->assertSame($value, StorageService::storedValue($value));
            $this->assertSame(['disk' => $disk, 'key' => 'videos/1/clip.mp4'], StorageService::location($value));
        }

        $this->assertSame('s3:videos/1/clip.mp4', StorageService::storedValue('videos/1/clip.mp4'));
        $this->assertSame('public:videos/1/clip.mp4', StorageService::storedValue(media_url('videos/1/clip.mp4')));
        $this->assertSame('s3:videos/1/clip.mp4', StorageService::storedValue('https://cdn.example.com/videos/1/clip.mp4'));
        $this->assertSame('https://cdn.example.com/videos/1/clip.mp4', StorageService::publicUrl('https://cdn.example.com/videos/1/clip.mp4'));
        $this->assertSame(media_url('videos/1/clip.mp4'), StorageService::publicUrl('videos/1/clip.mp4'));
        $this->assertTrue(str_contains(StorageService::publicUrl('local:videos/1/clip.mp4'), '/api/v1/media/'));
        $privateUrl = StorageService::publicUrl('local:videos/1/clip.mp4');
        $this->get(parse_url($privateUrl, PHP_URL_PATH) . '?' . parse_url($privateUrl, PHP_URL_QUERY))->assertStatus(404);

        $this->app->mergeConfig(['storage' => ['default' => 'public', 'disks' => ['s3' => ['url' => 'https://new.example.com']]]]);

        $this->assertSame('https://new.example.com/videos/1/clip.mp4', StorageService::publicUrl('s3:videos/1/clip.mp4'));
        $this->assertSame('https://music.example.com/track.mp3', StorageService::storedValue('https://music.example.com/track.mp3'));
    }

    public function testCreatingAVideoKeepsTheUploadDiskAfterTheDefaultChanges(): void
    {
        $user = $this->makeUser();
        storage('public')->put('videos/1/clip.mp4', 'uploaded');
        $this->useS3();

        $response = $this->asUser($user)->postJson('/api/v1/videos', [...$this->videoRights(), 'storage_path' => 'public:videos/1/clip.mp4'])
            ->assertStatus(201)
            ->assertJsonPath('data.video_url', media_url('videos/1/clip.mp4'));

        $this->assertDatabaseHas('videos', ['id' => $response->json('data.id'), 'storage_path' => 'public:videos/1/clip.mp4']);
    }

    public function testInvalidReferencesAndOtherOwnersFilesAreRejected(): void
    {
        $this->asUser($this->makeUser());
        storage('public')->put('videos/2/clip.mp4', 'video');

        foreach (['missing:videos/1/clip.mp4', 'public:../secret', 'public:/videos/1/clip.mp4', 'public:', 's3:videos/1/../../secret'] as $value) {
            $this->assertNull(StorageService::location($value));
            $this->postJson('/api/v1/videos', [...$this->videoRights(), 'storage_path' => $value])
                ->assertStatus(422)->assertJsonValidationErrors(['storage_path']);
        }

        $this->postJson('/api/v1/videos', [...$this->videoRights(), 'storage_path' => 'public:videos/2/clip.mp4'])
            ->assertStatus(422)->assertJsonValidationErrors(['storage_path']);
    }

    public function testCleanupPreservesFilesSharedAcrossLegacyAndPrefixedReferences(): void
    {
        $user = $this->makeUser();
        $key = 'videos/1/shared.mp4';
        storage('public')->put($key, 'shared');
        $legacy = $this->makeVideo($user, ['storage_path' => $key]);
        query('videos')->where('id', $legacy->id)->update(['storage_path' => $key]);
        $new = $this->makeVideo($user, ['storage_path' => "public:$key", 'status' => 'deleted']);

        (new DeleteVideo($new->id))->handle();
        (new DeleteUnusedMedia($user->id, ["public:$key"]))->handle();

        $this->assertNull(Video::find($new->id));
        $this->assertTrue(storage('public')->exists($key));

        query('videos')->where('id', $legacy->id)->update(['storage_path' => "public:$key"]);
        (new DeleteUnusedMedia($user->id, [$key]))->handle();
        $this->assertTrue(storage('public')->exists($key));

        PendingUploads::track($user->id, $key);
        query('pending_uploads')->update(['created_at' => now()->subHours(49)], ['path' => $key]);
        (new PruneUploads())->handle();
        $this->assertDatabaseCount('pending_uploads', 0);
        $this->assertTrue(storage('public')->exists($key));
    }

    public function testProcessingReadsAnExplicitPrivateLocalSource(): void
    {
        $user = $this->makeUser();
        $key = 'videos/1/source.mp4';
        storage('local')->put($key, pack('N', 24) . 'ftypmp42' . pack('N', 0) . 'mp42isom' . str_repeat("\0", 128));
        $video = $this->makeVideo($user, ['storage_path' => "local:$key", 'status' => 'processing']);
        $this->fakeVideoTools();

        (new ProcessVideo($video->id))->handle();

        $video->refresh();
        $this->assertSame('published', $video->status);
        $this->assertTrue(str_starts_with($video->storage_path, 'public:videos/1/'));
        $this->assertTrue(storage('public')->exists($this->mediaKey($video->storage_path)));
        $this->assertFalse(storage('local')->exists($key));
        $this->getJson('/api/v1/videos/' . $video->id)->assertOk()
            ->assertJsonPath('data.video_url', media_url($this->mediaKey($video->storage_path)));
    }
}
