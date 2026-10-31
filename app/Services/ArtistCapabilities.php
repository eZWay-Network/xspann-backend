<?php

namespace App\Services;

use App\Models\Artist;
use Spark\Http\Response;

class ArtistCapabilities
{
    /** Replace this resolver with verified billing access in Step 3. Tests bind a fixture resolver. */
    public function planFor(int $userId): string
    {
        return 'free';
    }

    public function forUser(int $userId): array
    {
        $plan = $this->planFor($userId);
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
