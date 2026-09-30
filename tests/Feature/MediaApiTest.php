<?php

namespace Tests\Feature;

use App\Models\Video;
use App\Services\StorageService;
use Tests\TestCase;

class MediaApiTest extends TestCase
{
    public function testPublicMediaStoresPathsAndResourcesReturnFullUrls(): void
    {
        $user = $this->makeUser();
        $this->asUser($user);
        $video = $this->postJson('/api/v1/videos', [
            'storage_path' => 'videos/1/clip.mp4',
            'thumbnail_url' => media_url('thumbnails/1/cover.jpg'),
            'sound_preview_url' => media_url('sounds/1/audio.mp3'),
        ])->assertStatus(201)
            ->assertJsonPath('data.video_url', media_url('videos/1/clip.mp4'))
            ->assertJsonPath('data.thumbnail_url', media_url('thumbnails/1/cover.jpg'))
            ->assertJsonPath('data.sound_preview_url', media_url('sounds/1/audio.mp3'));

        $this->assertDatabaseHas('videos', [
            'id' => $video->json('data.id'),
            'storage_path' => 'videos/1/clip.mp4',
            'thumbnail_url' => 'thumbnails/1/cover.jpg',
            'sound_preview_url' => 'sounds/1/audio.mp3',
        ]);
        $this->putJson('/api/v1/auth/profile', ['avatar' => media_url('avatars/1/avatar.png')])
            ->assertOk()->assertJsonPath('data.avatar', media_url('avatars/1/avatar.png'));
        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar' => 'avatars/1/avatar.png']);
        Video::find($video->json('data.id'))->update(['status' => 'published']);
        $this->flushHeaders()->getJson('/api/v1/users/suggestions')->assertOk()
            ->assertJsonPath('data.0.cover_url', media_url('thumbnails/1/cover.jpg'))
            ->assertJsonPath('data.0.cover_video_url', media_url('videos/1/clip.mp4'));
    }

    public function testS3MediaKeysBecomePublicUrlsForEveryModelField(): void
    {
        $this->useS3();
        $user = $this->makeUser();
        $user->update(['avatar' => 'avatars/1/avatar.png']);
        $video = $this->makeVideo($user, [
            'storage_path' => 'videos/1/cloud.mp4',
            'thumbnail_url' => 'thumbnails/1/cover.jpg',
            'sound_preview_url' => 'sounds/1/audio.mp3',
        ]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar' => 'https://cdn.example.com/avatars/1/avatar.png']);
        $this->assertDatabaseHas('videos', [
            'id' => $video->id,
            'storage_path' => 'https://cdn.example.com/videos/1/cloud.mp4',
            'thumbnail_url' => 'https://cdn.example.com/thumbnails/1/cover.jpg',
            'sound_preview_url' => 'https://cdn.example.com/sounds/1/audio.mp3',
        ]);

        $this->app->mergeConfig(['storage' => ['default' => 'public']]);
        $this->getJson('/api/v1/videos/' . $video->id)->assertOk()
            ->assertJsonPath('data.video_url', 'https://cdn.example.com/videos/1/cloud.mp4')
            ->assertJsonPath('data.thumbnail_url', 'https://cdn.example.com/thumbnails/1/cover.jpg')
            ->assertJsonPath('data.sound_preview_url', 'https://cdn.example.com/sounds/1/audio.mp3')
            ->assertJsonPath('data.user.avatar', 'https://cdn.example.com/avatars/1/avatar.png')
            ->assertJsonPath('data.user.cover_url', 'https://cdn.example.com/thumbnails/1/cover.jpg')
            ->assertJsonPath('data.user.cover_video_url', 'https://cdn.example.com/videos/1/cloud.mp4');
    }

    public function testStoredLocalMediaRemainsLocalAfterSwitchingToS3(): void
    {
        $user = $this->makeUser();
        $user->update(['avatar' => 'avatars/1/avatar.png']);
        $video = $this->makeVideo($user, ['thumbnail_url' => 'thumbnails/1/cover.jpg']);
        $this->useS3();
        $video->update(['caption' => 'Changed caption']);
        $user->update(['bio' => 'Changed bio']);
        $this->getJson('/api/v1/videos/' . $video->id)->assertOk()
            ->assertJsonPath('data.video_url', media_url('videos/1/sample.mp4'))
            ->assertJsonPath('data.thumbnail_url', media_url('thumbnails/1/cover.jpg'))
            ->assertJsonPath('data.user.avatar', media_url('avatars/1/avatar.png'));
    }

    public function testExternalUrlsAndNullMediaArePreserved(): void
    {
        $this->useS3();
        $user = $this->makeUser();
        $video = $this->makeVideo($user, ['sound_preview_url' => 'https://music.example.com/preview.mp3']);
        $this->getJson('/api/v1/videos/' . $video->id)->assertOk()
            ->assertJsonPath('data.sound_preview_url', 'https://music.example.com/preview.mp3')
            ->assertJsonPath('data.thumbnail_url', null)
            ->assertJsonPath('data.user.avatar', null);
    }

    public function testPrivateDiskIsSeparateFromPublicMedia(): void
    {
        storage('local')->put('secret.txt', 'private');
        $this->assertFalse(storage('public')->exists('secret.txt'));
        $this->assertSame('private', config('storage.disks.local.visibility'));
        $this->assertSame('http://localhost:8080/uploads/video.mp4', StorageService::publicUrl('video.mp4'));
    }
}
