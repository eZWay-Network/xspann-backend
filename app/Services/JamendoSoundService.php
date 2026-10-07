<?php

namespace App\Services;

use Spark\Facades\{Cache, Http};
use function count;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function strlen;

class JamendoSoundService
{
    public function search(string $keyword = '', int $limit = 20, int $page = 1): array
    {
        $tracks = $this->tracks(['search' => $keyword, 'limit' => $limit, 'offset' => ($page - 1) * $limit]);

        return [
            'data' => collect($tracks)
                ->filter($this->available(...))
                ->map($this->mapTrack(...))
                ->values()
                ->toArray(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $limit,
                'has_more' => count($tracks) === $limit
            ],
        ];
    }

    public function find(string $id): ?array
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $id)) {
            return null;
        }

        foreach ($this->tracks(['id' => $id, 'limit' => 1]) as $track) {
            if ((string) ($track['id'] ?? '') === $id && $this->available($track)) {
                return $this->mapTrack($track);
            }
        }

        return null;
    }

    public function mapTrack(array $track): array
    {
        return [
            'id' => (string) $track['id'],
            'provider' => 'jamendo',
            'external_id' => (string) $track['id'],
            'name' => $track['name'],
            'duration' => (int) ($track['duration'] ?? 0),
            'artist_id' => (string) ($track['artist_id'] ?? ''),
            'artist_name' => $track['artist_name'],
            'audio' => $track['audio'],
            'preview_url' => $track['audio'],
            'image' => $track['image'] ?? $track['album_image'] ?? null,
            'license_url' => $track['license_ccurl'] ?? null,
            'share_url' => $track['shareurl'] ?? null,
        ];
    }

    private function available(array $track): bool
    {
        return ($track['audiodownload_allowed'] ?? false) === true
            && (is_string($track['id'] ?? null) || is_int($track['id'] ?? null))
            && preg_match('/^[1-9][0-9]{0,19}$/D', (string) ($track['id'] ?? ''))
            && is_string($track['name'] ?? null)
            && is_string($track['artist_name'] ?? null)
            && is_string($track['audio'] ?? null)
            && strlen($track['audio']) <= 2048
            && filter_var($track['audio'], FILTER_VALIDATE_URL)
            && in_array(parse_url($track['audio'], PHP_URL_SCHEME), ['https', 'http'], true);
    }

    private function tracks(array $parameters): array
    {
        $clientId = config('app.jamendo_client_id');
        abort_unless(!empty($clientId), 503, 'Jamendo sounds are not configured.');

        $parameters = [
            'client_id' => (string) $clientId,
            'format' => 'json',
        ];

        if (!empty($parameters['id'] ?? null)) {
            $parameters['id'] = (string) $parameters['id'];
        } else {
            $parameters = [
                ...$parameters,
                'offset' => (string) ($parameters['offset'] ?? '0'),
                'limit' => (string) ($parameters['limit'] ?? '20'),
                'search' => (string) ($parameters['search'] ?? ''),
            ];
        }

        $key = 'jamendo.tracks.' . hash('sha256', json_encode($parameters));

        return Cache::remember($key, function () use ($parameters): array {
            try {
                $response = Http::timeout(10)
                    ->withRetry(0)
                    ->get('https://api.jamendo.com/v3.0/tracks/', $parameters);

                $payload = $response->json();
            } catch (\Throwable) {
                abort(503, 'Jamendo sounds are temporarily unavailable.');
            }

            abort_unless(
                $response->status() === 200 && is_array($payload)
                && ($payload['headers']['status'] ?? null) === 'success'
                && is_array($payload['results'] ?? null)
                && count(array_filter($payload['results'], 'is_array')) === count($payload['results']),
                503,
                'Jamendo sounds are temporarily unavailable.',
            );

            return $payload['results'];
        }, '5 minutes');
    }
}
