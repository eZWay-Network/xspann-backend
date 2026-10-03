<?php

return [
    'guard' => env('DEFAULT_AUTH_GUARD', 'default'),
    'model' => \App\Models\User::class,
    'guards' => [
        'session' => [
            'session_key' => 'user_id',
            'login_route' => 'login',
            'redirect_route' => 'dashboard',
            'cookie_enabled' => true,
            'cookie_name' => 'auth',
            'cookie_expire' => '6 months',
            'channels' => ['session'],
            'use_remember_token' => true, // Validate remember tokens from cookies
        ],
        'default' => [
            'jwt_expire' => (int) env('API_TOKEN_EXPIRATION', 43200) . ' minutes',
            'jwt_token_table' => 'jwt_access_tokens', // Table name for storing JWT tokens if needed
            'channels' => ['jwt'],
        ],
    ],
];