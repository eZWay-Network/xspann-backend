<?php

/**
 * Middleware configuration.
 *
 * This file returns an array of middleware classes used by the application.
 * Each middleware is associated with a key that can be used to reference it.
 *
 * @return array
 *   An associative array of middleware keys and their corresponding class names.
 */
return [
    'staff.perimeter' => \App\Http\Middlewares\StaffPerimeter::class,
    'staff.web' => \App\Http\Middlewares\StaffWeb::class,
    'staff.mfa' => \App\Http\Middlewares\StaffMfa::class,
    'staff.can' => \App\Http\Middlewares\StaffCan::class,
    'staff.inertia' => \App\Http\Middlewares\StaffInertia::class,
    'staff.disclosure' => \App\Http\Middlewares\StaffDisclosure::class,
    'restricted' => \App\Http\Middlewares\RestrictedAuthMiddleware::class,
    'auth' => \App\Http\Middlewares\AuthMiddleware::class,
    'cors' => \App\Http\Middlewares\CorsControl::class,
    'csrf' => \App\Http\Middlewares\CsrfProtectionMiddleware::class,
    'throttle' => \App\Http\Middlewares\ThrottleMiddleware::class,
];
