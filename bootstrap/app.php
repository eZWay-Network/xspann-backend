<?php

use Spark\Foundation\Application;

/**
 * This file is the entry point of the web application.
 *
 * It uses the tinymvc framework to create the application instance, register
 * service providers, middleware, and routes.
 */

/**
 * Create the application instance.
 *
 * @param string $path
 *   The root directory path of the application.
 */
return Application::create(path: dirname(__DIR__))
    /**
     * Register middleware in the application.
     *
     * @param Middleware $middleware
     *   The middleware service.
     *
     * @return void
     */
    ->withMiddleware(
        load: __DIR__ . '/middlewares.php',
        queue: ['cors', 'csrf']
    )

    /**
     * Register routes in the application.
     *
     * @param Router $router
     *   The router service.
     *
     * @return void
     */
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php'
    )

    /**
     * Register regularly scheduled jobs in the application.
     */
    ->withQueue([
        job(\App\Jobs\PruneUploads::class)->repeatDaily(),
        job([\App\Services\VideoRetry::class, 'retry'])->repeatHourly(),
    ]);
