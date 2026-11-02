<?php

namespace App\Http\Controllers\Staff\Auth;

use App\Http\Requests\Staff\LoginRequest;
use App\Services\Staff\{BrowserAuth, Sessions};
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};

final class LoginController
{
    public function index(Request $request): Response
    {
        if (Sessions::resolve($request)) {
            return Inertia::redirect((string) route('staff.dashboard'));
        }
        Inertia::withClearedHistory();

        return Inertia::render('auth/login', [
            'submit_url' => (string) route('staff.login.store'),
            'destination' => Sessions::destination(session('staff_destination')),
        ]);
    }

    public function store(LoginRequest $request): Response
    {
        BrowserAuth::begin($request, $request->validated()->toArray());

        return Inertia::redirect((string) route('staff.challenge'), 303);
    }

    public function logout(): Response
    {
        Sessions::logout();
        Inertia::withClearedHistory();

        return Inertia::location((string) route('staff.login'));
    }
}
