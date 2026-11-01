<?php

namespace App\Services;

use App\Models\User;
use Spark\Carbon;
use Spark\Facades\{Cache, DB, Hash};

final class PasswordRecovery
{
    public static function reset(string $email, string $token, string $password): void
    {
        Cache::lock('accounts.reset.' . hash('sha256', $email), 10)->block(5, function () use ($email, $token, $password): void {
            DB::transaction(function () use ($email, $token, $password): void {
                $reset = DB::table('password_reset_tokens')->where('email', $email)->lockForUpdate()->first();
                $user = User::where('email', $email)->lockForUpdate()->first();
                abort_unless($reset && $user && Hash::password($token, $reset->token)
                    && !Carbon::parse($reset->created_at)->addMinutes(config('app.password_reset_expiration_minutes', 60))->isPast(),
                    422, 'This password reset token is invalid.');
                if (!$user->update(['password' => $password, 'remember_token' => null])) {
                    throw new \RuntimeException('Password reset could not be saved.');
                }
                DB::table('jwt_access_tokens')->where('user_id', $user->id)->delete();
                DB::table('password_reset_tokens')->where('email', $user->email)->delete();
                DB::table('account_action_tokens')->where('user_id', $user->id)->delete();
                \App\Services\Staff\Sessions::revokeAll($user->id);
            });
        });
    }
}
