<?php

return [
    'key' => env('APP_KEY'), // Application key for encryption
    'debug' => env('APP_DEBUG', true), // Enable or disable debug mode

    'name' => env('APP_NAME', 'Spark'), // Application name
    'timezone' => env('APP_TIMEZONE', 'UTC'), // Application timezone
    'locale' => env('APP_LOCALE', 'en'), // Default language
    'url' => env('APP_URL', 'http://localhost:8080'), // Application URL

    // Directory paths
    'storage_dir' => dirname(__DIR__) . '/storage', // Storage directory
    'temp_dir' => dirname(__DIR__) . '/storage/framework/temp', // Temporary files directory
    'upload_dir' => dirname(__DIR__) . '/storage/app/public', // Public upload directory
    'views_dir' => dirname(__DIR__) . '/resources/views', // Template directory
    'locale_dir' => dirname(__DIR__) . '/resources/languages', // Language files directory

    // URL settings
    'media_url' => '/uploads/', // Media URL
    'asset_url' => '/assets/', // Asset URL

    // Trusted proxy IPs/CIDRs, e.g. ['10.0.0.0/8', '172.16.0.0/12', '127.0.0.1'].
    // Use '*' only if your load balancer always overwrites X-Forwarded-For.
    'trusted_proxies' => array_filter(
        array_unique(
            explode(',', env('TRUSTED_PROXIES', ''))
        )
    ),
    // Select cf-connecting-ip only when trusted peers overwrite that header.
    'trusted_proxy_header' => env('TRUSTED_PROXY_HEADER', 'x-forwarded-for'),

    // Other settings
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost:3000')),
    'google_client_id' => env('GOOGLE_CLIENT_ID'),
    // Additional trusted clients requesting tokens for the Web/server audience.
    'google_allowed_presenter_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('GOOGLE_ALLOWED_PRESENTER_IDS', ''))
    ))),
    'api_token_expiration_minutes' => (int) env('API_TOKEN_EXPIRATION', 43200),
    'password_reset_expiration_minutes' => (int) env('PASSWORD_RESET_EXPIRATION', 60),
    'ffmpeg' => env('FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
];
