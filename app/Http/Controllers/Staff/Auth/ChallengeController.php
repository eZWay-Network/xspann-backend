<?php

namespace App\Http\Controllers\Staff\Auth;

use App\Http\Requests\Staff\ChallengeRequest;
use App\Services\Staff\{BrowserAuth, Sessions};
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};

final class ChallengeController
{
    public function index(Request $request): Response
    {
        $context = Sessions::resolve($request);

        if (!$context && !BrowserAuth::pending()) {
            return Inertia::redirect((string) route('staff.login'));
        }
        Inertia::withClearedHistory();

        return Inertia::render('auth/challenge', [
            'requires_credential' => $context !== null,
            'submit_url' => (string) route('staff.challenge.store'),
            'google_client_id' => config('app.google_client_id'),
        ]);
    }

    public function store(ChallengeRequest $request): Response
    {
        $destination = BrowserAuth::challenge($request, $request->validated()->toArray(), Sessions::resolve($request));

        return Inertia::redirect($destination, 303);
    }
}
