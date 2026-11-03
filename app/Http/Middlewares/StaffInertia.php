<?php

namespace App\Http\Middlewares;

use App\Http\Resources\Staff\SharedProps;
use App\Queries\Staff\Attention;
use App\Services\Staff\{Calendar, Context};
use Inertia\Facades\Inertia;
use Spark\Http\Request;

final class StaffInertia
{
    public function handle(Request $request, \Closure $next): mixed
    {
        $context = Context::from($request);

        $timezone = $context->preferences()['timezone'];
        Inertia::share([
            'workspace' => Inertia::always(\App\Services\Staff\Settings::workspace()),
            'auth' => Inertia::always(SharedProps::auth($context)),
            'navigation' => Inertia::always(SharedProps::navigation($context)),
            'preferences' => Inertia::always(SharedProps::preferences($context)),
            'clock' => Inertia::once(fn () => Calendar::clock($timezone))
                ->as('staff-clock:' . timezone_version_get() . ':' . $timezone)->until(3600),
            'attention' => Inertia::always(Attention::read($context)),
            'urls' => Inertia::always([
                'dashboard' => (string) route('staff.dashboard'),
                'clock' => Inertia::once(fn () => Calendar::clock($timezone))
                ->as('staff-clock:' . timezone_version_get() . ':' . $timezone)->until(3600),
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
