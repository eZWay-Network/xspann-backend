<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'video_url' => fake()->url(),
            'storage_path' => 'videos/'.fake()->uuid().'.mp4',
            'thumbnail_url' => fake()->optional()->imageUrl(720, 1280),
            'caption' => fake()->sentence(),
            'sound_name' => fake()->randomElement(['Original sound', 'Royal Pulse', 'Midnight Drift']),
            'sound_artist' => fake()->userName(),
            'sound_provider' => 'local',
            'sound_external_id' => null,
            'sound_preview_url' => null,
            'location_name' => fake()->city(),
            'visibility' => 'public',
            'high_quality_upload' => true,
            'scheduled_at' => null,
            'pinned_at' => null,
            'trim_start' => 0,
            'trim_end' => null,
            'cover_time' => null,
            'crop_mode' => 'fit',
            'text_overlay' => null,
            'original_audio_muted' => false,
            'duration' => fake()->numberBetween(5, 120),
            'status' => Video::STATUS_PUBLISHED,
            'views_count' => 0,
            'likes_count' => 0,
            'comments_count' => 0,
            'saves_count' => 0,
            'shares_count' => 0,
        ];
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => Video::STATUS_PROCESSING,
            'video_url' => null,
            'thumbnail_url' => null,
            'duration' => null,
        ]);
    }
}
