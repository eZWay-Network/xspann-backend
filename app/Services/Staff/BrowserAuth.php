<?php

namespace App\Services\Staff;

use App\Models\User;
use App\Services\AuditTrail;
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
        if ($user?->status === 'suspended') {
            \App\Services\Moderation::expireSuspensions($user->id);
            $user->refresh();
        }
        $member = $user ? DB::table('staff_accounts')->where('user_id', $user->id)->where('enabled', true)->first() : null;

        if (!Sessions::eligible($user) || !$member || !Permissions::forRole($member->role)) {
            // Keep a password-hash cost even for an unknown identity.
            password_verify((string) ($input['password'] ?? ''), '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');

            throw new StaffException('The credentials or staff access could not be verified.', 422, 'password');
        }
        self::verifyPassword($user, $input['password']);

        Cache::lock("accounts.security.$user->id", 10)->block(5, fn () => DB::transaction(function () use ($user, $member, $request): void {
            $current = DB::table('staff_accounts')->where('user_id', $user->id)->where('enabled', true)->lockForUpdate()->first();
            $fresh = User::whereKey($user->id)->lockForUpdate()->first();
            if (!$current || (int) $current->version !== (int) $member->version || !Sessions::eligible($fresh)
                || !hash_equals(self::credentialVersion($user), self::credentialVersion($fresh))) {
                throw new StaffException('Staff access changed. Please sign in again.', 403);
            }
            Sessions::establish($fresh, $current, $request);
        }));
    }

    public static function credentialVersion(User $user): string
    {
        return hash('sha256', (string) $user->password);
    }

    public static function challenge(Request $request, array $input, ?Context $context): string
    {
        if (!$context) {
            throw new StaffException('Please sign in again.', 401);
        }
        self::throttle('confirmation:' . $context->user->id);
        Cache::lock("accounts.security.{$context->user->id}", 10)->block(5, fn () => DB::transaction(function () use ($context, $input): void {
            $current = Sessions::requireCurrent($context);
            self::verifyPassword($current->user, $input['password'] ?? '');
            Sessions::confirm($current);
            AuditTrail::record('staff.password_confirmed', [], $current->user->id, 'staff');
        }));

        return Sessions::destination(Session::pull('staff_destination'));
    }
}
