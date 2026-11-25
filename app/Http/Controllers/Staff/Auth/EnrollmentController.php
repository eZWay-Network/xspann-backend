<?php

namespace App\Http\Controllers\Staff\Auth;

use App\Http\Requests\Staff\LoginRequest;
use App\Models\User;
use App\Services\Staff\{BrowserAuth, Sessions, StaffException, Team};
use Inertia\Facades\Inertia;
use Spark\Facades\{DB, Session};
use Spark\Http\Response;

final class EnrollmentController
{
    public function link(string $token): Response
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new StaffException('This invitation is unavailable.', 403);
        }
        $hash = hash('sha256', $token);
        Team::invitation($hash);
        Session::put('staff_invitation_hash', $hash);

        return Inertia::redirect((string) route('staff.enroll'));
    }

    public function index(): Response
    {
        $hash = Session::get('staff_invitation_hash', '');
        Team::invitation($hash);
        Inertia::withClearedHistory();

        return Inertia::render('auth/enroll', [
            'credential_url' => (string) route('staff.enroll.credential'),
        ]);
    }

    public function credential(LoginRequest $request): Response
    {
        $invitation = Team::invitation(Session::get('staff_invitation_hash', ''));
        $user = User::find($invitation->user_id);
        BrowserAuth::throttle('enroll:' . $invitation->user_id);

        if (!Sessions::eligible($user) || mb_strtolower(trim($request->validated('email'))) !== mb_strtolower($user->email)) {
            throw new StaffException('Use the credentials for the invited account.', 422, 'credential');
        }
        BrowserAuth::verifyPassword($user, $request->validated('password'));
        $hash = Session::get('staff_invitation_hash');
        Sessions::logout();
        Session::put('staff_invitation_hash', $hash);
        $user = Team::accept($hash, $user->id, BrowserAuth::credentialVersion($user));
        Session::forget('staff_invitation_hash');
        Sessions::establish($user, DB::table('staff_accounts')->where('user_id', $user->id)->first(), $request);

        return Inertia::redirect((string) route('staff.dashboard'), 303);
    }
}
