<?php

namespace App\Http\Middlewares;

use Spark\Foundation\Http\Middlewares\AuthMiddleware as Middleware;
use Spark\Http\Request;

class AuthMiddleware extends Middleware
{
    public function handle(Request $request, \Closure $next, ...$guards): mixed
    {
        return parent::handle($request, function (Request $request) use ($next) {
            if ($request->user('status') === 'suspended') {
                \App\Services\Moderation::expireSuspensions($request->user('id'));
                $request->user()->refresh();
            }
            abort_unless($request->user('status') === 'active', 403, 'This account is not active.');

            abort_unless($request->user()->hasVerifiedEmail(), 403, 'Please verify your email address.');

            abort_unless((bool) $request->user('onboarded_at'), 403, 'Complete account onboarding.');

            return $next($request);
        }, ...$guards);
    }

    protected function failed(Request $request, array $guards): mixed
    {
        abort(401, 'Unauthenticated.');

        return null; // This line will never be reached, but is added to satisfy the return type.
    }
}
