<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideo;
use App\Models\User;
use App\Models\Video;
use App\Services\VideoMetadataExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VideoApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_view_public_feed(): void
    {
        $localVideo = Video::factory()->create([
            'storage_path' => 'videos/1/local.mp4',
            'thumbnail_url' => 'http://localhost/storage/thumbnails/1/local.jpg',
            'sound_preview_url' => 'http://localhost/storage/sounds/1/local.mp3',
        ]);
        Video::factory()->create();
        Video::factory()->processing()->create();
        Video::factory()->create(['visibility' => 'private']);
        Video::factory()->create(['visibility' => 'followers']);

        $this->getJson('/api/v1/feed')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment([
                'id' => $localVideo->id,
                'video_url' => 'http://localhost:8000/media/videos/1/local.mp4',
                'thumbnail_url' => 'http://localhost:8000/media/thumbnails/1/local.jpg',
                'sound_preview_url' => 'http://localhost:8000/media/sounds/1/local.mp3',
            ])
            ->assertJsonStructure([
                'data' => [
                    ['id', 'video_url', 'thumbnail_url', 'caption', 'duration', 'user', 'stats', 'viewer'],
                ],
                'meta',
            ]);

        $this->getJson('/api/v1/videos')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_followers_visibility_is_enforced_in_feed_and_profile_videos(): void
    {
        $creator = User::factory()->create();
        $follower = User::factory()->create();
        $stranger = User::factory()->create();
        $publicVideo = Video::factory()->for($creator)->create(['visibility' => 'public', 'caption' => 'Public']);
        $followersVideo = Video::factory()->for($creator)->create(['visibility' => 'followers', 'caption' => 'Followers']);
        $privateVideo = Video::factory()->for($creator)->create(['visibility' => 'private', 'caption' => 'Private']);

        $follower->following()->attach($creator->id);

        $this->getJson('/api/v1/feed')
            ->assertOk()
            ->assertJsonFragment(['id' => $publicVideo->id])
            ->assertJsonMissing(['id' => $followersVideo->id])
            ->assertJsonMissing(['id' => $privateVideo->id]);

        Sanctum::actingAs($stranger);
        $this->getJson("/api/v1/users/{$creator->username}/videos")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonMissing(['caption' => 'Followers']);

        Sanctum::actingAs($follower);
        $this->getJson("/api/v1/users/{$creator->username}/videos")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['caption' => 'Followers']);

        Sanctum::actingAs($creator);
        $this->getJson("/api/v1/videos/{$privateVideo->id}")
            ->assertOk();
    }

    public function test_public_routes_accept_optional_bearer_viewer(): void
    {
        $creator = User::factory()->create();
        $follower = User::factory()->create();
        $publicVideo = Video::factory()->for($creator)->create(['visibility' => 'public', 'caption' => 'Public']);
        $followersVideo = Video::factory()->for($creator)->create(['visibility' => 'followers', 'caption' => 'Followers']);
        $follower->following()->attach($creator->id);

        $token = $follower->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/feed')
            ->assertOk()
            ->assertJsonFragment(['id' => $publicVideo->id])
            ->assertJsonFragment(['id' => $followersVideo->id]);

        $this->withToken($token)->getJson("/api/v1/users/{$creator->username}")
            ->assertOk()
            ->assertJsonPath('data.following', true);
    }

    public function test_video_view_tracking_increments_once_per_recent_viewer(): void
    {
        $video = Video::factory()->create(['views_count' => 0]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson("/api/v1/videos/{$video->id}/view")
            ->assertCreated()
            ->assertJsonPath('data.views_count', 1);

        $this->postJson("/api/v1/videos/{$video->id}/view")
            ->assertOk()
            ->assertJsonPath('data.views_count', 1);
    }

    public function test_user_can_create_video_record_and_dispatch_processing_job(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/videos', [
            'storage_path' => 'videos/1/example.mp4',
            'caption' => 'First upload #xspannrnb',
            'sound_name' => 'Royal Pulse',
            'sound_artist' => 'XSpann RNB Sounds',
            'sound_provider' => 'local',
            'location_name' => 'Dhaka',
            'visibility' => 'public',
            'high_quality_upload' => true,
            'trim_start' => 1.5,
            'trim_end' => 12.5,
            'cover_time' => 3.2,
            'crop_mode' => 'fill',
            'text_overlay' => 'New drop',
            'original_audio_muted' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', Video::STATUS_PROCESSING)
            ->assertJsonPath('data.sound_name', 'Royal Pulse')
            ->assertJsonPath('data.sound_artist', 'XSpann RNB Sounds')
            ->assertJsonPath('data.sound_provider', 'local')
            ->assertJsonPath('data.location_name', 'Dhaka')
            ->assertJsonPath('data.visibility', 'public')
            ->assertJsonPath('data.edit.trim_start', 1.5)
            ->assertJsonPath('data.edit.crop_mode', 'fill')
            ->assertJsonPath('data.edit.text_overlay', 'New drop')
            ->assertJsonPath('data.tags.0', '#xspannrnb');

        Queue::assertPushed(ProcessVideo::class);
    }

    public function test_video_processing_job_publishes_video(): void
    {
        $video = Video::factory()->processing()->create(['storage_path' => 'videos/test.mp4']);

        app()->bind(VideoMetadataExtractor::class, fn () => new class
        {
            public function extract(Video $video): array
            {
                return [
                    'duration' => 17,
                    'thumbnail_url' => 'http://localhost/storage/thumbnails/test.jpg',
                ];
            }
        });

        (new ProcessVideo($video->id))->handle();

        $video->refresh();

        $this->assertSame(Video::STATUS_PUBLISHED, $video->status);
        $this->assertNotNull($video->video_url);
        $this->assertSame(17, $video->duration);
        $this->assertSame('http://localhost/storage/thumbnails/test.jpg', $video->thumbnail_url);
    }

    public function test_user_can_list_all_own_videos_for_posts_manager(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Video::factory()->for($owner)->create(['caption' => 'Published post', 'created_at' => now()->subDay()]);
        Video::factory()->for($owner)->create(['caption' => 'Pinned post', 'pinned_at' => now()->subMinute(), 'created_at' => now()->subWeek()]);
        Video::factory()->for($owner)->processing()->create(['caption' => 'Processing post', 'visibility' => 'private']);
        Video::factory()->for($owner)->create(['caption' => 'Deleted post', 'status' => Video::STATUS_DELETED]);
        Video::factory()->for($other)->create(['caption' => 'Other creator post']);

        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/me/videos?limit=10')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.caption', 'Pinned post')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonMissing(['caption' => 'Deleted post'])
            ->assertJsonMissing(['caption' => 'Other creator post']);
    }

    public function test_user_can_only_update_own_video_editor_metadata(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $video = Video::factory()->for($owner)->create([
            'caption' => 'Before',
            'visibility' => 'public',
            'filter_settings' => null,
        ]);

        Sanctum::actingAs($other);
        $this->patchJson("/api/v1/videos/{$video->id}", ['caption' => 'Nope'])->assertForbidden();

        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/videos/{$video->id}", [
            'caption' => 'After',
            'visibility' => 'private',
            'pinned' => true,
            'trim_start' => 2,
            'trim_end' => 14,
            'crop_mode' => 'fill',
            'filter_settings' => [
                'brightness' => 106,
                'contrast' => 118,
                'saturation' => 92,
                'warmth' => 12,
                'preset' => 'custom',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.caption', 'After')
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.pinned_at', fn ($value) => is_string($value))
            ->assertJsonPath('data.edit.trim_start', 2)
            ->assertJsonPath('data.edit.trim_end', 14)
            ->assertJsonPath('data.edit.crop_mode', 'fill')
            ->assertJsonPath('data.edit.filter_settings.brightness', 106);

        $video->refresh();
        $this->assertSame('After', $video->caption);
        $this->assertSame('private', $video->visibility);
        $this->assertNotNull($video->pinned_at);

        $this->patchJson("/api/v1/videos/{$video->id}", ['pinned' => false])
            ->assertOk()
            ->assertJsonPath('data.pinned_at', null);

        $this->assertNull($video->fresh()->pinned_at);
    }

    public function test_user_can_only_delete_own_video(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $video = Video::factory()->for($owner)->create();

        Sanctum::actingAs($other);
        $this->deleteJson("/api/v1/videos/{$video->id}")->assertForbidden();

        Sanctum::actingAs($owner);
        $this->deleteJson("/api/v1/videos/{$video->id}")->assertOk();

        $this->assertSame(Video::STATUS_DELETED, $video->fresh()->status);
    }
}
