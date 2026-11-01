<?php

namespace App\Providers;

use Spark\Facades\Gate;
use Spark\Foundation\Providers\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(\App\Services\Billing\PaymentProvider::class, \App\Services\Billing\StripeProvider::class);
    }

    public function boot(): void
    {
        // Inertia boots Blade eagerly. Concurrent workers can create this directory together.
        $views = storage_dir('framework/views');

        if (!is_dir($views) && !@mkdir($views, 0755, true) && !is_dir($views)) {
            throw new \RuntimeException('The view cache directory could not be created.');
        }

        Gate::define('model.update', fn($model) => user('id') === $model->user_id);
        Gate::define('model.delete', fn($model) => user('id') === $model->user_id);
    }
}
