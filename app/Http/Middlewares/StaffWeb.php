<?php

namespace App\Http\Middlewares;

use App\Services\Staff\Sessions;
use Inertia\Facades\Inertia;
use Spark\Http\Request;

final class StaffWeb
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $context = Sessions::resolve($request);

        if (!$context) {
            Sessions::logout();

            if ($request->isGet()) {
                session(['staff_destination' => Sessions::destination($request->getUri())]);
            }

            return Inertia::location((string) route('staff.login'));
        }
        $request->attributes['staff_context'] = $context;

        return $next($request);
    }
}
