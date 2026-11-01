<?php

namespace App\Http\Middlewares;

use App\Services\Staff\{Context, Sessions};
use Inertia\Facades\Inertia;
use Spark\Http\Request;

final class StaffMfa
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $context = Context::from($request);

        if (!$context->session->mfa_expires_at || $context->session->mfa_expires_at <= Sessions::timestamp()) {
            session(['staff_destination' => $request->isGet() ? Sessions::destination($request->getUri()) : '/']);

            return Inertia::location((string) route('staff.challenge'));
        }

        return $next($request);
    }
}
