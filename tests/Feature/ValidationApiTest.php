<?php

namespace Tests\Feature;

use Tests\TestCase;

class ValidationApiTest extends TestCase
{
    public function testRegistrationAndProfileMatchLaravelValidation(): void
    {
        $owner = $this->makeUser();
        $this->makeUser('taken');
        $this->postJson('/api/v1/auth/register', [
            'username' => 'taken',
            'email' => 'taken@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
            'avatar' => 'not-a-url',
        ])->assertStatus(422)->assertJsonValidationErrors(['username', 'email', 'password', 'avatar']);
        $this->asUser($owner)->putJson('/api/v1/auth/profile', [
            'username' => 'taken',
            'avatar' => 'not-a-url',
            'bio' => str_repeat('x', 121),
        ])->assertStatus(422)->assertJsonValidationErrors(['username', 'avatar', 'bio']);
        $this->putJson('/api/v1/auth/profile', ['username' => 'alice'])->assertOk();
    }

    public function testVideoValidationMatchesLaravelEditorContract(): void
    {
        $this->asUser($this->makeUser());
        $this->postJson('/api/v1/videos', [
            'thumbnail_url' => 'not-a-url',
            'caption' => str_repeat('x', 2201),
            'sound_name' => str_repeat('x', 121),
            'sound_artist' => str_repeat('x', 121),
            'visibility' => 'everyone',
            'trim_start' => 12,
            'trim_end' => 5,
            'cover_time' => -1,
            'crop_mode' => 'square',
            'text_overlay' => str_repeat('x', 121),
            'sound_provider' => 'unsupported',
            'sound_preview_url' => 'not-a-url',
        ])->assertStatus(422)->assertJsonValidationErrors([
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

    public function testOptionalRegistrationMediaIsPersistedAndReturned(): void
    {
        $this->useS3();
        $response = $this->postJson('/api/v1/auth/register', [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'avatar' => 'https://cdn.example.com/avatars/avatar.png',
            'bio' => 'Creator',
        ])->assertStatus(201)
            ->assertJsonPath('data.user.avatar', 'https://cdn.example.com/avatars/avatar.png')
            ->assertJsonPath('data.user.bio', 'Creator');
        $this->assertDatabaseHas('users', ['id' => $response->json('data.user.id'), 'avatar' => 'https://cdn.example.com/avatars/avatar.png']);
    }
}
