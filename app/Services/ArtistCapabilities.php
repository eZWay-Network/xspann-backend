<?php

namespace App\Services;

use App\Models\Artist;
use Spark\Http\Response;

class ArtistCapabilities
{
    /** Paid capabilities derive exclusively from verified, unrevoked invoice periods. */
    public function planFor(int $userId): string
    {
        return \App\Services\Billing\Billing::access($userId)?->plan_code ?? 'free';
    }

    public function forUser(int $userId): array
    {
        return $this->forPlan($this->planFor($userId));
    }

    public function forArtist(Artist $artist): array
    {
        return $this->forPlan($artist->billing_plan ?? 'free');
    }

    private function forPlan(string $plan): array
    {
        $plans = config('artists.plans');

        return ['plan' => isset($plans[$plan]) ? $plan : 'free', ...($plans[$plan] ?? $plans['free'])];
    }

    public function require(int $userId, string $capability): void
    {
        if (empty($this->forUser($userId)[$capability])) {
            $this->deny($capability);
        }
    }

    public function playlistsReadOnly(Artist $artist): bool
    {
        return $artist->playlists()->count() > $this->forUser($artist->user_id)['playlists'];
    }

    public function deny(string $capability): never
    {
        abort(Response::STATUS_FORBIDDEN, 'Artist capability or limit unavailable: ' . $capability . '.');
    }
}
