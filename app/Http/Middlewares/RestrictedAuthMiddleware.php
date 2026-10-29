<?php

namespace App\Http\Middlewares;

use Spark\Foundation\Http\Middlewares\AuthMiddleware;
use Spark\Http\Request;

class RestrictedAuthMiddleware extends AuthMiddleware
{
    public function handle(Request $request, \Closure $next, ...$guards): mixed
    {
        return parent::handle($request, function (Request $request) use ($next) {
            abort_unless(in_array($request->user('status'), ['active', 'suspended'], true), 403, 'This account is not available.');
            abort_unless($request->user()->hasVerifiedEmail(), 403, 'Verify your email address.');

            return $next($request);
        }, ...$guards);
    }

    protected function failed(Request $request, array $guards): mixed
    {
        abort(401, 'Unauthenticated.');
    }
}
