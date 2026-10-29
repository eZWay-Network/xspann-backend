<?php

return [
    'server' => [
        'host' => env('RELAY_SERVER_HOST', '127.0.0.1'),
        'port' => (int) env('RELAY_SERVER_PORT', 8080),
        'max_connections' => 500,
        'memory_limit_mb' => 256,
    ],
    'apps' => [
        [
            'app_id' => env('RELAY_APP_ID', '1'),
            'key' => env('RELAY_APP_KEY'),
            'secret' => env('RELAY_APP_SECRET'),
            'allowed_origins' => [env('RELAY_ALLOWED_ORIGIN', 'http://localhost:8080')],
            'max_connections' => 500,
            'max_message_size' => 16384,
            'client_events' => false,
            'cache_ttl' => 1800,
            'max_cached_channels' => 1000,
            'max_cache_size' => 10485760,
        ]
    ],
    'client' => [
        'host' => env('RELAY_HOST', '127.0.0.1'),
        'port' => (int) env('RELAY_PORT', 8080),
        'scheme' => env('RELAY_SCHEME', 'http'),
        'timeout' => 5,
    ],
];
