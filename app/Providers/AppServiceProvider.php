<?php

namespace App\Providers;

use Spark\Facades\Gate;
use Spark\Http\Auth;
use Spark\Foundation\Providers\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            abstract: Auth::class,
            concrete: fn() => new Auth(config: ['channels' => ['jwt'], 'jwt_token_table' => 'jwt_access_tokens', 'jwt_expire' => config('app.api_token_expiration_minutes') . ' minutes'])
        );
    }

    public function boot(): void
    {
        Gate::define('model.update', fn($model) => user('id') === $model->user_id);
        Gate::define('model.delete', fn($model) => user('id') === $model->user_id);
    }
}