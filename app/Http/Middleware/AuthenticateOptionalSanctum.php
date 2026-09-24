<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateOptionalSanctum
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->bearerToken()) {
            $user = Auth::guard('sanctum')->user();

            if ($user) {
                Auth::shouldUse('sanctum');
                $request->setUserResolver(fn (?string $guard = null) => $guard
                    ? Auth::guard($guard)->user()
                    : $user);
            }
        }

        return $next($request);
    }
}
