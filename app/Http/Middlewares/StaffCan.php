<?php

namespace App\Http\Middlewares;

use App\Services\Staff\Context;
use Spark\Http\Request;

final class StaffCan
{
    public function handle(Request $request, \Closure $next, string $ability): mixed
    {
        Context::from($request)->authorize($ability);

        return $next($request);
    }
}
