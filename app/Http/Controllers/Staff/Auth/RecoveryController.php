<?php

namespace App\Http\Controllers\Staff\Auth;

use App\Http\Requests\Staff\{ForgotPasswordRequest, ResetPasswordRequest};
use App\Models\User;
use App\Services\{AccountNotifications, PasswordRecovery};
use App\Services\Staff\Sessions;
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};

final class RecoveryController
{
    public function forgot(): Response
    {
        return Inertia::render('auth/forgot-password', ['submit_url' => (string) route('staff.password.email')]);
    }

    public function email(ForgotPasswordRequest $request, AccountNotifications $notifications): Response
    {
        $user = User::where('email', mb_strtolower(trim($request->validated('email'))))->first();
        if ($user) {
            $notifications->reset($user, true);
        }
        Inertia::flash('success', 'If that email exists, a password reset link has been sent.');

        return Inertia::redirect((string) route('staff.password.forgot'), 303);
    }

    public function reset(Request $request): Response
    {
        $email = $request->query('email', '');
        $token = $request->query('token', '');
        abort_unless(is_string($email) && is_string($token) && strlen($email) <= 255 && strlen($token) <= 128, 422);

        return Inertia::render('auth/reset-password', ['email' => $email, 'token' => $token, 'submit_url' => (string) route('staff.password.reset.store')]);
    }

    public function store(ResetPasswordRequest $request): Response
    {
        $input = $request->validated();
        PasswordRecovery::reset(mb_strtolower(trim($input['email'])), $input['token'], $input['password']);
        Sessions::logout();
        Inertia::flash('success', 'Password reset. Sign in with your authenticator.');

        return Inertia::redirect((string) route('staff.login'), 303);
    }
}
