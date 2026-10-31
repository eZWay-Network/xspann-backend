<?php

namespace Tests\Support;

use App\Models\{Artist, User};
use App\Services\ArtistCapabilities;

trait ArtistFixtures
{
    protected function makeArtist(User $owner, array $attributes = []): Artist
    {
        return Artist::create([
            'user_id' => $owner->id,
            'stage_name' => $owner->name,
            'slug' => $owner->username . '-music',
            'data_since' => now(),
            ...$attributes,
        ])->refresh();
    }

    protected function artistPlan(User $owner, string $plan): void
    {
        $this->app->instance(ArtistCapabilities::class, new class($owner->id, $plan) extends ArtistCapabilities {
            public function __construct(private int $ownerId, private string $plan)
            {
            }

            public function planFor(int $userId): string
            {
                return $userId === $this->ownerId ? $this->plan : 'free';
            }
        });
    }
}
