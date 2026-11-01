<?php

namespace App\Http\Middlewares;

use Spark\Http\Request;

/** Disclosures are explicit fetches or media requests, never page visits or prefetches. */
final class StaffDisclosure
{
    public function handle(Request $request, \Closure $next): mixed
    {
        foreach (['X-Inertia', 'X-Inertia-Partial-Component', 'X-Inertia-Partial-Data', 'X-Inertia-Partial-Except'] as $header) {
            abort_if($request->header($header) !== null, 400);
        }

        $purpose = strtolower($request->header('Purpose', '') . ' ' . $request->header('Sec-Purpose', ''));
        abort_if(str_contains($purpose, 'prefetch'), 400);

        $request->attributes['staff_disclosure'] = true;

        return $next($request);
    }
}
