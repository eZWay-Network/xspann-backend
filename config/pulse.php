<?php

return [

    'enabled' => env('PULSE_ENABLED', false),

    'path' => env('PULSE_PATH', 'pulse'),

    'domain' => env('PULSE_DOMAIN', null),

    'middleware' => [
        \Spark\Pulse\Http\Middleware\AuthorizePulse::class,
        \App\Http\Middlewares\StaffWeb::class,
    ],

    'storage' => [
        'driver' => env('PULSE_STORAGE_DRIVER', 'database'),
        'table' => 'pulse_entries',
        'connection' => env('PULSE_DB_CONNECTION', null),
        'prune_hours' => 24,
    ],

    'slow_thresholds' => [
        'request' => 500,
        'query' => 50,
        'job' => 1000,
        'http_client' => 1000,
    ],

    'ignore_paths' => [
        'favicon.ico',
        'api/v1/conversations*',
        'api/v1/notifications*',
        'api/v1/realtime/*',
        'api/v1/broadcasting/*',
    ],

    'ignore_commands' => [
        'pulse:*',
        'queue:work',
    ],

    'sensitive_keys' => [
        'password',
        'password_confirmation',
        'secret',
        'token',
        'authorization',
        'cookie',
        'api_key',
        'access_token',
        'app_key',
        'set-cookie',
        'x-api-key',
        'proxy-authorization',
        'x-csrf-token',
        'x-xsrf-token',
        '_token',
        'refresh_token',
        'client_secret',
        'body',
        'channel_name',
        'socket_id',
        'auth',
    ],

    // Exclusions match complete, case-sensitive identifiers. Use * as a wildcard.
    'watchers' => [
        'requests' => [
            'enabled' => true,
            'exclude' => [], // Request path
            'ignore_status_codes' => [],
        ],
        'commands' => [
            'enabled' => true,
            'exclude' => [], // Command name
        ],
        'jobs' => [
            'enabled' => true,
            'exclude' => [
                'App\Jobs\PublishRealtime',
                'App\Jobs\StaffMaintenance',
                'App\Jobs\PromotionMaintenance',
                'App\Jobs\DeliverAnnouncements',
                'App\Jobs\BillingMaintenance',
                'App\Jobs\PublishReleases',
            ], // Job classes or display name
        ],
        'cache' => [
            'enabled' => true,
            'exclude' => [], // Cache key
        ],
        'events' => [
            'enabled' => true,
            'exclude' => [], // Event name
        ],
        'exceptions' => [
            'enabled' => true,
            'exclude' => [], // Exception class
        ],
        'gates' => [
            'enabled' => true,
            'exclude' => [], // Ability name
        ],
        'http_client' => [
            'enabled' => true,
            'exclude' => ['*/apps/*'], // Signed private Relay publication
        ],
        'logs' => [
            'enabled' => true,
            'exclude' => [], // Log level or message
        ],
        'mail' => [
            'enabled' => true,
            'exclude' => [], // Subject or To address
            'record_body' => false,
        ],
        'models' => [
            'enabled' => true,
            'exclude' => [], // Model class
        ],
        'queries' => [
            'enabled' => true,
            'exclude' => [], // SQL statement or connection name
            'detect_duplicates' => true,
            'record_bindings' => false,
        ],
        'views' => [
            'enabled' => true,
            'exclude' => [], // Recorded template name or compiled path
        ],
        'language_files' => [
            'enabled' => true,
            'exclude' => [], // Recorded translation file path
        ],
        'performance' => [
            'enabled' => true,
            'exclude' => [], // Request path that triggers the sample
            'sample_rate' => 1,
        ],
    ],

];
