<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UploadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_upload_endpoint_returns_local_fallback_when_cloud_is_not_configured(): void
    {
        config(['filesystems.video_disk' => 'public']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/uploads/videos/signed-url', [
            'filename' => 'clip.mp4',
            'content_type' => 'video/mp4',
        ])->assertOk()
            ->assertJsonPath('data.upload_method', 'multipart')
            ->assertJsonPath('data.upload_url', url('/api/v1/uploads/videos/local'))
            ->assertJsonPath('data.field_name', 'file');
    }

    public function test_signed_upload_endpoint_falls_back_when_spaces_credentials_are_missing(): void
    {
        config([
            'filesystems.video_disk' => 'spaces',
            'filesystems.disks.spaces.key' => null,
            'filesystems.disks.spaces.secret' => null,
            'filesystems.disks.spaces.bucket' => null,
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/uploads/videos/signed-url', [
            'filename' => 'clip.mp4',
            'content_type' => 'video/mp4',
        ])->assertOk()
            ->assertJsonPath('data.upload_method', 'multipart');
    }

    public function test_user_can_upload_video_to_local_fallback_storage(): void
    {
        Storage::fake('public');
        config(['filesystems.video_disk' => 'public']);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/uploads/videos/local', [
            'file' => UploadedFile::fake()->create('clip.mp4', 128, 'video/mp4'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.upload_method', 'multipart')
            ->assertJsonStructure(['data' => ['storage_path', 'video_url']]);

        Storage::disk('public')->assertExists($response->json('data.storage_path'));
    }

    public function test_user_can_upload_video_to_local_storage_in_chunks(): void
    {
        Storage::fake('public');
        config(['filesystems.video_disk' => 'public']);
        Sanctum::actingAs(User::factory()->create());

        $uploadId = 'd8d96929-b939-4cae-8d6a-1a8eaf6a8910';

        $this->post('/api/v1/uploads/videos/chunk', [
            'upload_id' => $uploadId,
            'chunk_index' => 0,
            'total_chunks' => 2,
            'filename' => 'clip.mp4',
            'content_type' => 'video/mp4',
            'total_size' => 10,
            'chunk' => UploadedFile::fake()->createWithContent('clip.part0', 'hello'),
        ])->assertCreated()
            ->assertJsonPath('data.upload_method', 'chunked');

        $this->post('/api/v1/uploads/videos/chunk', [
            'upload_id' => $uploadId,
            'chunk_index' => 1,
            'total_chunks' => 2,
            'filename' => 'clip.mp4',
            'content_type' => 'video/mp4',
            'total_size' => 10,
            'chunk' => UploadedFile::fake()->createWithContent('clip.part1', 'world'),
        ])->assertCreated();

        $response = $this->postJson('/api/v1/uploads/videos/complete', [
            'upload_id' => $uploadId,
            'total_chunks' => 2,
            'filename' => 'clip.mp4',
            'content_type' => 'video/mp4',
            'total_size' => 10,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.upload_method', 'chunked')
            ->assertJsonStructure(['data' => ['storage_path', 'video_url']]);

        Storage::disk('public')->assertExists($response->json('data.storage_path'));
        $this->assertSame('helloworld', Storage::disk('public')->get($response->json('data.storage_path')));
    }

    public function test_local_media_endpoint_supports_byte_ranges(): void
    {
        config(['filesystems.video_disk' => 'public']);
        Storage::disk('public')->put('videos/1/range.mp4', 'abcdefghij');

        try {
            $response = $this->withHeaders([
                'Range' => 'bytes=2-5',
            ])->get('/media/videos/1/range.mp4');

            $response->assertStatus(206);
            $response->assertHeader('Accept-Ranges', 'bytes');
            $response->assertHeader('Content-Range', 'bytes 2-5/10');
            $this->assertSame('cdef', $response->streamedContent());
        } finally {
            Storage::disk('public')->delete('videos/1/range.mp4');
        }
    }

    public function test_chunked_video_upload_requires_every_chunk_before_completion(): void
    {
        Storage::fake('public');
        config(['filesystems.video_disk' => 'public']);
        Sanctum::actingAs(User::factory()->create());

        $uploadId = 'f28d08bb-6290-436a-8018-12c9dcf15b2f';

        $this->post('/api/v1/uploads/videos/chunk', [
            'upload_id' => $uploadId,
            'chunk_index' => 0,
            'total_chunks' => 2,
            'filename' => 'clip.mp4',
            'content_type' => 'video/mp4',
            'total_size' => 10,
            'chunk' => UploadedFile::fake()->createWithContent('clip.part0', 'hello'),
        ])->assertCreated();

        $this->postJson('/api/v1/uploads/videos/complete', [
            'upload_id' => $uploadId,
            'total_chunks' => 2,
            'filename' => 'clip.mp4',
            'content_type' => 'video/mp4',
            'total_size' => 10,
        ])->assertUnprocessable();
    }

    public function test_user_can_upload_audio_to_local_sound_storage(): void
    {
        Storage::fake('public');
        config(['filesystems.video_disk' => 'public']);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/uploads/sounds/local', [
            'file' => UploadedFile::fake()->create('beat.mp3', 256, 'audio/mpeg'),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.upload_method', 'multipart')
            ->assertJsonStructure(['data' => ['storage_path', 'audio_url']]);

        $this->assertStringStartsWith('sounds/', $response->json('data.storage_path'));
        Storage::disk('public')->assertExists($response->json('data.storage_path'));
    }

    public function test_user_can_upload_avatar_image(): void
    {
        Storage::fake('public');
        config(['filesystems.video_disk' => 'public']);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/v1/uploads/avatar', [
            'file' => UploadedFile::fake()->image('avatar.jpg', 256, 256),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.upload_method', 'multipart')
            ->assertJsonStructure(['data' => ['storage_path', 'avatar_url']]);

        Storage::disk('public')->assertExists($response->json('data.storage_path'));
    }
}
