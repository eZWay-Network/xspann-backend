<?php

namespace App\Providers;

use Spark\Facades\Gate;
use Spark\Foundation\Providers\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Register any application services here
    }

    public function boot(): void
    {
        Gate::define('model.update', fn($model) => user('id') === $model->user_id);
        Gate::define('model.delete', fn($model) => user('id') === $model->user_id);
    }
}