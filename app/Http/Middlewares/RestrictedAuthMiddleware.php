<?php

namespace App\Http\Middlewares;

use Spark\Foundation\Http\Middlewares\AuthMiddleware;
use Spark\Http\Request;

class RestrictedAuthMiddleware extends AuthMiddleware
{
    public function handle(Request $request, \Closure $next, ...$guards): mixed
    {
        return parent::handle($request, function (Request $request) use ($next) {
            if ($request->user('status') === 'suspended') {
                \App\Services\Moderation::expireSuspensions($request->user('id'));
                $request->user()->refresh();
            }
            $deletedAppeal = $request->user('status') === 'deleted' && in_array(trim($request->getPath(), '/'),
                ['api/v1/me/moderation-decisions', 'api/v1/me/moderation-appeals', 'api/v1/auth/logout'], true);
            abort_unless($deletedAppeal || in_array($request->user('status'), ['active', 'suspended', 'banned'], true), 403, 'This account is not available.');
            abort_unless($request->user()->hasVerifiedEmail(), 403, 'Verify your email address.');

            return $next($request);
        }, ...$guards);
    }

    protected function failed(Request $request, array $guards): mixed
    {
        abort(401, 'Unauthenticated.');
    }
}
