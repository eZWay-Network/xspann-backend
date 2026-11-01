<?php

namespace App\Http\Middlewares;

use App\Http\Resources\Staff\SharedProps;
use App\Queries\Staff\Attention;
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Http\Request;

final class StaffInertia
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $context = Context::from($request);

        Inertia::share([
            'workspace' => Inertia::always(\App\Services\Staff\Settings::workspace()),
            'auth' => Inertia::always(SharedProps::auth($context)),
            'navigation' => Inertia::always(SharedProps::navigation($context)),
            'preferences' => Inertia::always(SharedProps::preferences($context)),
            'attention' => Inertia::always(Attention::read($context)),
            'urls' => Inertia::always([
                'dashboard' => (string) route('staff.dashboard'),
                'attention' => (string) route('staff.attention'),
                'profile' => (string) route('staff.profile'),
                'security' => (string) route('staff.security'),
                'logout' => (string) route('staff.logout'),
                'challenge' => (string) route('staff.challenge'),
            ]),
        ]);

        return $next($request);
    }
}
