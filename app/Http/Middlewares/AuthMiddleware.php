<?php

namespace App\Http\Middlewares;

use Spark\Foundation\Http\Middlewares\AuthMiddleware as Middleware;
use Spark\Http\Request;

class AuthMiddleware extends Middleware
{
    public function handle(Request $request, \Closure $next, ...$guards): mixed
    {
        return parent::handle($request, function (Request $request) use ($next) {
            abort_unless($request->user('status') === 'active', 403, 'This account is not active.');
            abort_unless($request->user()->hasVerifiedEmail(), 403, 'Please verify your email address.');

            return $next($request);
        }, ...$guards);
    }

    protected function failed(Request $request, array $guards): mixed
    {
        abort(401, 'Unauthenticated.');

        return null; // This line will never be reached, but is added to satisfy the return type.
    }
}