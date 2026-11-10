<?php

namespace App\Jobs;

use App\Services\Promotions\{Availability, Promotion};
use Spark\Facades\{Http, Log};
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;
use Throwable;
use function count;
use function is_array;

class CheckPromotionSurface implements JobInterface
{
    use Dispatchable;

    public function handle(): void
    {
        $platforms = Availability::enabledPlatforms();
        if (!$platforms) {
            return;
        }

        $surface = config('promotions.surface');
        $platform = $platforms[0];
        try {
            $placements = Http::make('GET')
                ->withAccept('application/json')
                ->withTimeout(4)
                ->withRetry(0)
                ->withOptions([CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_FOLLOWLOCATION => false])
                ->get(rtrim(config('app.url'), '/') . '/api/v1/promotions/placements', [
                    'surface' => $surface,
                    'platform' => $platform,
                ]);
            $payload = json_decode($placements->body());
            if (
                $placements->status() !== 200 || !is_array($payload->data ?? null)
                || ($payload->meta->surface ?? null) !== $surface || ($payload->meta->platform ?? null) !== $platform
                || ($payload->meta->capacity ?? null) !== Promotion::capacity() || count($payload->data) > Promotion::capacity()
            ) {
                Log::warning('Shared Discover placements check returned an invalid response.');
                return;
            }
        } catch (Throwable $error) {
            Log::warning('Shared Discover placements request failed ({type}).', ['type' => $error::class]);
            return;
        }

        Promotion::transaction(function () use ($platforms): void {
            $reference = 'discover-api-' . gmdate('YmdHi');
            foreach (array_intersect($platforms, Availability::enabledPlatforms()) as $client) {
                Availability::record([
                    'platform' => $client,
                    'available' => true,
                    'reference' => $reference,
                    'note' => 'Verified shared Discover candidate selection for enabled integrations.',
                ]);
            }
        });
    }
}
