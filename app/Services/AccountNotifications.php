<?php

namespace App\Services;

use App\Jobs\SendAccountEmail;
use App\Models\User;
use Spark\Carbon;
use Spark\Http\Request;
use Spark\Facades\{Hash, Lock};
use function is_scalar;
use function is_string;

class AccountNotifications
{
    public function reset(User $user): void
    {
        $key = 'accounts.reset.' . hash('sha256', $user->email);

        Lock::withLock($key, function () use ($user) {
            $existing = query('password_reset_tokens')
                ->where('email', $user->email)
                ->first();

            abort_if(
                $existing && Carbon::parse((string) $existing->created_at)->gt(now()->subSeconds(60)),
                422,
                'Please wait before retrying.'
            );

            $token = Hash::random(64);

            query('password_reset_tokens')->upsert([
                'email' => $user->email,
                'token' => Hash::password($token),
                'created_at' => now(),
            ], ['email'], ['token', 'created_at']);

            $url = rtrim(config('app.frontend_url'), '/') . '/reset-password?' . http_build_query(['token' => $token, 'email' => $user->email]);

            $body = "Reset your password using this link (valid for 60 minutes):<br/><br/>" .
                "<a href=\"$url\">Reset Password</a> <br/><br/>or open the link in your browser. <br><small>$url</small>"
                . "<br/><br/>If you did not request a password reset, please ignore this email.";

            SendAccountEmail::dispatch($user->id, 'Reset your password', $body);
        }, timeout: 10, waitTimeout: 5);
    }

    public function verificationUrl(User $user, ?int $expires = null): string
    {
        $expires ??= time() + config('app.email_verification_expiration_minutes', 60) * 60;
        $url = home_url('/auth/email/verify/' . $user->id . '/' . sha1($user->email) . '?expires=' . $expires);

        return "$url&signature=" . hash_hmac('sha256', $url, config('app.key'));
    }

    public function verify(User $user): void
    {
        $url = htmlspecialchars($this->verificationUrl($user), ENT_QUOTES, 'UTF-8');
        $body = "Welcome to our platform! Please verify your email address by clicking the link below. This link will expire in " . config('app.email_verification_expiration_minutes', 60) . " minutes.<br/> <br/>"
            . "<a href=\"$url\">Click here to verify your email address</a><br/><br/>or open the link in your browser."
            . "<br><small>$url</small><br/><br/>Thank you for joining us!";

        SendAccountEmail::dispatch($user->id, 'Verify your email address', $body);
    }

    public function validVerification(Request $request): bool
    {
        $expires = $request->query('expires');
        $signature = $request->query('signature');

        if (!is_string($signature) || (!is_scalar($expires) || !ctype_digit((string) $expires) || time() > (int) $expires)) {
            return false;
        }

        // Bind the actual origin, path, and every query parameter.
        [$url, $query] = array_pad(explode('?', $request->getUrl(), 2), 2, '');

        $parameters = array_filter(
            explode('&', $query),
            fn($part) => explode('=', $part, 2)[0] !== 'signature'
        );

        $unsigned = rtrim("$url?" . implode('&', $parameters), '?');

        return hash_equals(hash_hmac('sha256', $unsigned, config('app.key')), $signature);
    }
}
