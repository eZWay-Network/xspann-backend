<?php

namespace App\Services\Staff;

use App\Models\User;
use App\Services\{AccountSecurity, AuditTrail};
use Spark\Exceptions\Http\ResponseException;
use Spark\Facades\{Cache, DB, Hash, Session};
use Spark\Http\Request;

final class BrowserAuth
{
    public static function throttle(string $identity): void
    {
        $key = 'staff.identity.' . hash('sha256', mb_strtolower(trim($identity)));
        Cache::lock($key, 5)->block(3, function () use ($key): void {
            $attempts = array_values(array_filter(Cache::get($key, []), fn($time) => $time > time() - 300));

            if (count($attempts) >= 10) {
                throw new StaffException('Too many attempts. Try again in five minutes.', 429);
            }
            $attempts[] = time();
            Cache::put($key, $attempts, '+5 minutes');
        });
    }

    public static function verifyPassword(User $user, string $password): void
    {
        if (!$user->password || !Hash::verify($password, $user->password)) {
            throw new StaffException('The credentials or staff access could not be verified.', 422, 'password');
        }
    }

    public static function begin(Request $request, array $input): void
    {
        $email = mb_strtolower(trim($input['email']));
        self::throttle($email);
        $user = User::where('email', $email)->first();
        $member = $user ? DB::table('staff_accounts')->where('user_id', $user->id)->where('enabled', true)->first() : null;

        if (!Sessions::eligible($user) || !$member || !Permissions::forRole($member->role)) {
            // Keep a password-hash cost even for an unknown identity.
            password_verify((string) ($input['password'] ?? ''), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');

            throw new StaffException('The credentials or staff access could not be verified.', 422, 'password');
        }
        self::verifyPassword($user, $input['password']);

        Sessions::logout();
        Session::regenerate(true);
        Session::put('staff_pending', [
            'user_id' => $user->id,
            'version' => (int) $member->version,
            'credential_version' => self::credentialVersion($user),
            'expires' => time() + config('staff.pending_seconds'),
            'destination' => Sessions::destination($input['destination'] ?? null),
        ]);
    }

    public static function credentialVersion(User $user): string
    {
        return hash('sha256', (string) $user->password);
    }

    public static function pending(): ?array
    {
        $pending = Session::get('staff_pending');

        if (!is_array($pending) || ($pending['expires'] ?? 0) <= time()) {
            Session::forget('staff_pending');

            return null;
        }

        return $pending;
    }

    public static function challenge(Request $request, array $input, ?Context $context): string
    {
        $pending = $context ? null : self::pending();
        $id = $context?->user->id ?? $pending['user_id'] ?? null;

        if (!$id) {
            throw new StaffException('Your sign-in challenge expired. Please sign in again.', 401);
        }
        self::throttle('challenge:' . $id);
        $user = User::find($id);

        if (!Sessions::eligible($user)) {
            throw new StaffException('This staff account is unavailable.', 403);
        }

        if ($context) {
            self::verifyPassword($user, $input['password'] ?? '');
        }

        Cache::lock("accounts.security.$id", 10)->block(5, fn() => DB::transaction(function () use ($user, $pending, $context, $input, $request): void {
            $member = DB::table('staff_accounts')->where('user_id', $user->id)->where('enabled', true)->lockForUpdate()->first();
            $version = $context ? (int) $context->membership->version : (int) $pending['version'];
            $freshUser = User::find($user->id);

            if ($pending && (!$freshUser || !hash_equals($pending['credential_version'] ?? '', self::credentialVersion($freshUser)))) {
                throw new StaffException('Your credentials changed. Please sign in again.', 403);
            }

            if (!$member || (int) $member->version !== $version || !Permissions::forRole($member->role) || !Sessions::eligible(User::find($user->id))) {
                throw new StaffException('Staff access has changed. Please sign in again.', 403);
            }

            try {
                AccountSecurity::consumeStaffTotp($member, $input['mfa_code']);
            } catch (ResponseException) {
                throw new StaffException('Enter a fresh six-digit authenticator code.', 422, 'mfa_code');
            }

            if ($context) {
                Sessions::refreshMfa($context);
            } else {
                Sessions::establish($user, $member, $request);
            }
            AuditTrail::record('staff.challenge_completed', [], $user->id, 'staff');
        }));

        return Sessions::destination($pending['destination'] ?? Session::pull('staff_destination'));
    }
}
