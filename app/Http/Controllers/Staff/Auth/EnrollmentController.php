<?php

namespace App\Http\Controllers\Staff\Auth;

use App\Http\Requests\Staff\{LoginRequest, ChallengeRequest};
use App\Models\User;
use App\Services\Staff\{BrowserAuth, Sessions, StaffException, Team};
use App\Services\Totp;
use Inertia\Facades\Inertia;
use Spark\Facades\{DB, Hash, Session};
use Spark\Http\{Response};

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
        $enrollment = Session::get('staff_enrollment');

        if ($enrollment && $enrollment['expires'] <= time()) {
            Session::forget('staff_enrollment');
            Session::forget('staff_enrollment_secret_once');
            $enrollment = null;
        }
        Inertia::withClearedHistory();

        return Inertia::render('auth/enroll', [
            'stage' => $enrollment ? 'authenticator' : 'credential',
            'secret' => Session::pull('staff_enrollment_secret_once'),
            'credential_url' => (string) route('staff.enroll.credential'),
            'complete_url' => (string) route('staff.enroll.complete'),
            'restart_url' => (string) route('staff.enroll.restart'),
            'google_client_id' => config('app.google_client_id'),
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
        BrowserAuth::credential($user, $request->validated()->toArray());
        $hash = Session::get('staff_invitation_hash');
        Sessions::logout();
        Session::put('staff_invitation_hash', $hash);
        $secret = Totp::secret();
        Session::put('staff_enrollment', [
            'credential_version' => BrowserAuth::credentialVersion($user),
            'user_id' => $user->id,
            'secret' => Hash::encrypt($secret),
            'expires' => time() + config('staff.pending_seconds'),
        ]);
        Session::put('staff_enrollment_secret_once', $secret);

        return Inertia::redirect((string) route('staff.enroll'), 303);
    }

    public function complete(ChallengeRequest $request): Response
    {
        $enrollment = Session::get('staff_enrollment');

        if (!$enrollment || $enrollment['expires'] <= time()) {
            Session::forget('staff_enrollment');

            throw new StaffException('Enrollment expired. Restart to set up a new authenticator.', 422);
        }
        BrowserAuth::throttle('enroll:' . $enrollment['user_id'], $enrollment['credential_version']);
        $user = Team::accept(Session::get('staff_invitation_hash', ''), Hash::decrypt($enrollment['secret']), $request->validated('mfa_code'), $enrollment['user_id'], $enrollment['credential_version']);
        Session::forget('staff_invitation_hash');
        Session::forget('staff_enrollment_secret_once');
        Sessions::establish($user, DB::table('staff_accounts')->where('user_id', $user->id)->first(), $request);

        return Inertia::redirect((string) route('staff.dashboard'), 303);
    }

    public function restart(): Response
    {
        Session::forget('staff_enrollment');
        Session::forget('staff_enrollment_secret_once');

        return Inertia::redirect((string) route('staff.enroll'), 303);
    }
}
