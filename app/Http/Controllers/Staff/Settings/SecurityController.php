<?php

namespace App\Http\Controllers\Staff\Settings;

use App\Http\Requests\Staff\{ChallengeRequest, PasswordRequest};
use App\Services\{AuditTrail, Totp};
use App\Services\Staff\{BrowserAuth, Context, Sessions, StaffException, Team};
use Inertia\Facades\Inertia;
use Spark\Facades\{DB, Hash, Session};
use Spark\Http\{Request, Response};

final class SecurityController
{
    public function index(Request $request): Response
    {
        $context = Context::from($request);
        $sessions = DB::table('staff_sessions')->where('user_id', $context->user->id)->whereNotNull('membership_version')
            ->whereNull('revoked_at')->where('expires_at', '>', Sessions::timestamp())->where('absolute_expires_at', '>', Sessions::timestamp())
            ->orderBy('id', 'desc')->all(['id', 'device_label', 'authenticated_at', 'last_activity_at', 'expires_at']);
        $replacement = Session::get('staff_factor_replacement');

        if ($replacement && $replacement['expires'] <= time()) {
            Session::forget('staff_factor_replacement');
            Session::forget('staff_factor_secret_once');
            $replacement = null;
        }

        return Inertia::render('settings/security', [
            'sessions' => array_map(fn($row) => [
                'id' => (int) $row->id,
                'device' => $row->device_label,
                'current' => (int) $row->id === (int) $context->session->id,
                'authenticated_at' => Sessions::iso($row->authenticated_at),
                'last_activity_at' => Sessions::iso($row->last_activity_at),
                'expires_at' => Sessions::iso($row->expires_at),
                'revoke_url' => (string) route('staff.sessions.revoke', ['session' => $row->id]),
            ], $sessions),
            'has_password' => (bool) $context->user->password,
            'password_url' => (string) route('staff.security.password'),
            'factor_url' => (string) route('staff.security.factor'),
            'factor_complete_url' => (string) route('staff.security.factor.complete'),
            'factor_pending' => $replacement !== null,
            'factor_secret' => Session::pull('staff_factor_secret_once'),
            'revoke_all_url' => (string) route('staff.sessions.revoke_all'),
            'confirmation_url' => (string) route('staff.challenge')->withQuery(['destination' => '/settings/security']),
            'page' => ['title' => 'Security & sessions', 'breadcrumbs' => []],
        ]);
    }

    public function revoke(Request $request, int $session): Response
    {
        $context = Context::from($request);
        $count = DB::table('staff_sessions')->where('id', $session)->where('user_id', $context->user->id)->whereNull('revoked_at')
            ->update(['revoked_at' => Sessions::timestamp()]);

        if ($count !== 1) {
            throw new StaffException('This session is no longer active.', 404);
        }
        AuditTrail::record('staff.session_revoked', ['session_id' => $session], $context->user->id, 'staff');

        if ($session === (int) $context->session->id) {
            Sessions::logout();

            return Inertia::location((string) route('staff.login'));
        }
        Inertia::flash('success', 'Session revoked.');

        return Inertia::redirect((string) route('staff.security'), 303);
    }

    public function revokeAll(Request $request): Response
    {
        Sessions::revokeAll(Context::from($request)->user->id);
        Sessions::logout();

        return Inertia::location((string) route('staff.login'));
    }

    public function password(PasswordRequest $request): Response
    {
        $context = Context::from($request);
        BrowserAuth::challenge($request, $request->validated()->only('password', 'mfa_code')->toArray(), $context);
        Team::locked(function () use ($request, $context): void {
            $context = Sessions::requireCurrent($context);

            if (!$context->user->update(['password' => $request->validated('new_password'), 'remember_token' => null])) {
                throw new StaffException('Your password could not be saved. Please try again.', 503);
            }
            DB::table('jwt_access_tokens')->where('user_id', $context->user->id)->delete();
            DB::table('password_reset_tokens')->where('email', $context->user->email)->delete();
            DB::table('account_action_tokens')->where('user_id', $context->user->id)->delete();
            Sessions::revokeAll($context->user->id);
            AuditTrail::record('staff.password_changed', [], $context->user->id, 'staff');
        });
        Sessions::logout();

        return Inertia::location((string) route('staff.login'));
    }

    public function factor(ChallengeRequest $request): Response
    {
        $context = Context::from($request);
        BrowserAuth::challenge($request, $request->validated()->toArray(), $context);
        $secret = Totp::secret();
        Session::put('staff_factor_replacement', [
            'user_id' => $context->user->id,
            'version' => (int) $context->membership->version,
            'secret' => Hash::encrypt($secret),
            'expires' => time() + config('staff.confirmation_seconds'),
        ]);
        Session::put('staff_factor_secret_once', $secret);

        return Inertia::redirect((string) route('staff.security'), 303);
    }

    public function completeFactor(ChallengeRequest $request): Response
    {
        $context = Context::from($request);
        BrowserAuth::throttle('factor:' . $context->user->id);
        Team::locked(function () use ($request, $context): void {
            $context = Sessions::requireCurrent($context);
            $replacement = Session::get('staff_factor_replacement');
            $member = DB::table('staff_accounts')->where('user_id', $context->user->id)->where('enabled', true)->lockForUpdate()->first();

            if (!$replacement || !$member || $replacement['expires'] <= time() || $replacement['user_id'] !== $context->user->id || $replacement['version'] !== (int) $member->version) {
                throw new StaffException('Authenticator setup expired. Start again with your current authenticator.', 422);
            }
            $counter = Totp::counter(Hash::decrypt($replacement['secret']), $request->validated('mfa_code'));

            if ($counter === null) {
                throw new StaffException('Enter the code from your new authenticator.', 422, 'mfa_code');
            }
            DB::table('staff_accounts')->where('id', $member->id)->update([
                'mfa_secret' => $replacement['secret'],
                'last_counter' => $counter,
                'version' => (int) $member->version + 1,
                'updated_at' => Sessions::timestamp(),
            ]);
            Sessions::revokeAll($context->user->id);
            AuditTrail::record('staff.authenticator_replaced', [], $context->user->id, 'staff');
        });
        Sessions::logout();

        return Inertia::location((string) route('staff.login'));
    }
}
