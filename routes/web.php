<?php

use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api-docs', function () {
    $routes = collect(Route::getRoutes())
        ->map(function ($route) {
            $uri = '/'.$route->uri();

            return [
                'methods' => collect($route->methods())->reject(fn ($method) => $method === 'HEAD')->values()->all(),
                'uri' => $uri,
                'action' => $route->getActionName(),
                'middleware' => collect($route->middleware())->values()->all(),
                'scope' => match (true) {
                    str_contains($uri, '/auth/') => 'Auth',
                    str_contains($uri, '/feed') => 'Feed',
                    str_contains($uri, '/uploads/') => 'Uploads',
                    str_contains($uri, '/users/') => 'Users',
                    str_contains($uri, '/comments/') || str_contains($uri, '/like') || str_contains($uri, '/save') || str_contains($uri, '/share') => 'Social Actions',
                    str_contains($uri, '/videos') => 'Videos',
                    default => 'Other',
                },
            ];
        })
        ->filter(fn ($route) => str_starts_with($route['uri'], '/api/v1/'))
        ->sortBy([
            fn ($a, $b) => array_search($a['scope'], ['Auth', 'Feed', 'Videos', 'Uploads', 'Users', 'Social Actions', 'Other'], true)
                <=> array_search($b['scope'], ['Auth', 'Feed', 'Videos', 'Uploads', 'Users', 'Social Actions', 'Other'], true),
            fn ($a, $b) => $a['uri'] <=> $b['uri'],
        ])
        ->values();

    return view('api-docs', [
        'routes' => $routes,
        'scopes' => $routes->groupBy('scope'),
        'baseUrl' => rtrim((string) config('app.url'), '/').'/api/v1',
        'storageDisk' => config('filesystems.video_disk'),
        'queueConnection' => config('queue.default'),
    ]);
});

Route::get('/media/{path}', [MediaController::class, 'show'])->where('path', '.*');
