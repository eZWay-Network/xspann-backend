<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_validates_required_unique_and_confirmed_fields(): void
    {
        User::factory()->create([
            'username' => 'taken_name',
            'email' => 'taken@example.com',
        ]);

        $this->postJson('/api/v1/auth/register', [
            'username' => 'taken_name',
            'email' => 'taken@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
            'avatar' => 'not-a-url',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'email', 'password', 'avatar']);
    }

    public function test_login_validates_credentials(): void
    {
        User::factory()->create([
            'email' => 'creator@example.com',
            'password' => 'password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'creator@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_profile_update_validates_unique_username_and_avatar_url(): void
    {
        User::factory()->create(['username' => 'already_taken']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/profile', [
            'username' => 'already_taken',
            'avatar' => 'not-a-url',
            'bio' => str_repeat('a', 121),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'avatar', 'bio']);
    }

    public function test_video_creation_validates_storage_path_and_urls(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/videos', [
            'thumbnail_url' => 'not-a-url',
            'caption' => str_repeat('a', 2201),
            'sound_name' => str_repeat('a', 121),
            'sound_artist' => str_repeat('a', 121),
            'visibility' => 'everyone',
            'trim_start' => 12,
            'trim_end' => 5,
            'cover_time' => -1,
            'crop_mode' => 'square',
            'text_overlay' => str_repeat('a', 121),
            'sound_provider' => 'spotify',
            'sound_preview_url' => 'not-a-url',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'storage_path',
                'thumbnail_url',
                'caption',
                'sound_name',
                'sound_artist',
                'visibility',
                'trim_end',
                'cover_time',
                'crop_mode',
                'text_overlay',
                'sound_provider',
                'sound_preview_url',
            ]);
    }

    public function test_signed_upload_validates_filename_and_content_type(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/uploads/videos/signed-url', [
            'filename' => '',
            'content_type' => 'image/png',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['filename', 'content_type']);
    }

    public function test_local_upload_requires_a_supported_video_file(): void
    {
        Storage::fake('public');
        config(['filesystems.video_disk' => 'public']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/uploads/videos/local', [
            'file' => UploadedFile::fake()->image('poster.png'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_local_sound_upload_requires_a_supported_audio_file(): void
    {
        Storage::fake('public');
        config(['filesystems.video_disk' => 'public']);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/uploads/sounds/local', [
            'file' => UploadedFile::fake()->image('poster.png'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_comment_creation_validates_body_and_same_video_parent(): void
    {
        $user = User::factory()->create();
        $video = Video::factory()->create();
        $otherVideo = Video::factory()->create();
        $otherComment = $otherVideo->comments()->create([
            'user_id' => $user->id,
            'body' => 'Other thread.',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/videos/{$video->id}/comments", [
            'body' => '',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['body']);

        $this->postJson("/api/v1/videos/{$video->id}/comments", [
            'body' => 'Reply in wrong thread.',
            'parent_id' => $otherComment->id,
        ])->assertUnprocessable();
    }

    public function test_share_validates_channel(): void
    {
        $video = Video::factory()->create();

        $this->postJson("/api/v1/videos/{$video->id}/share", [
            'channel' => 'unsupported_channel',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['channel']);
    }
}
