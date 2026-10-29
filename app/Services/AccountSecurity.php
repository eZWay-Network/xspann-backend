<?php

namespace App\Services;

use App\Models\{AuthIdentity, User};
use App\Services\Social\GoogleAuth;
use Spark\Facades\{Auth, Cache, DB, Hash};

class AccountSecurity
{
    public static function session(): string
    {
        return hash('sha256', (string) Auth::token());
    }

    public static function issue(int $userId, string $purpose, int $seconds, ?string $session = null): string
    {
        $token = bin2hex(random_bytes(32));

        DB::table('account_action_tokens')->insert([
            'user_id' => $userId,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $token),
            'session_hash' => $session,
            'expires_at' => now()->addSeconds($seconds)->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
        ]);

        return $token;
    }

    /** Call inside the transaction that performs the authorized mutation. */
    public static function consume(string $token, string $purpose, ?int $userId = null, ?string $session = null): int
    {
        $query = DB::table('account_action_tokens')
            ->where('token_hash', hash('sha256', $token))
            ->where('purpose', $purpose)
            ->where('expires_at', '>', now()->toDateTimeString());

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }
        if ($session !== null) {
            $query->where('session_hash', $session);
        }

        $record = $query->first();

        // Spark consumes/reset a builder after first(); use a fresh conditional delete.
        abort_unless(
            $record && DB::table('account_action_tokens')->where('id', $record->id)
                ->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now()->toDateTimeString())
                ->delete() === 1,
            422,
            'This confirmation is invalid or expired.'
        );

        return (int) $record->user_id;
    }

    public static function reauthenticate(User $user, array $input): array
    {
        $purpose = $input['purpose'];
        $password = $input['password'] ?? null;
        $google = $input['google_token'] ?? null;
        abort_unless((bool) $password !== (bool) $google, 422, 'Supply exactly one credential.');

        if ($password) {
            abort_unless($user->password && Hash::verify($password, $user->password), 422, 'The credentials are incorrect.');
        } else {
            $claims = app(GoogleAuth::class)->verify($google);
            abort_unless($claims['iat'] >= time() - 300 && AuthIdentity::where('user_id', $user->id)
                ->where('provider', 'google')->where('provider_id', $claims['sub'])->exists(), 422, 'Use a fresh credential from your linked Google account.');
        }

        return Cache::lock("accounts.security.$user->id", 10)->block(5, fn() => DB::transaction(function () use ($user, $input, $purpose): array {
            $staff = DB::table('staff_accounts')->where('user_id', $user->id)->where('enabled', true)->lockForUpdate()->first();
            abort_if($purpose === 'staff' && !$staff, 403, 'Staff access is required.');

            if ($staff) {
                $counter = Totp::counter(Hash::decrypt($staff->mfa_secret), $input['mfa_code'] ?? '', (int) $staff->last_counter);
                abort_unless($counter !== null, 422, 'A fresh authenticator code is required.');
                abort_unless(DB::table('staff_accounts')->where('id', $staff->id)->where('last_counter', $staff->last_counter)
                    ->update(['last_counter' => $counter, 'updated_at' => now()->toDateTimeString()]) === 1, 409, 'Retry with a fresh authenticator code.');
            }

            if ($purpose === 'staff') {
                abort_unless($user->status === 'active' && $user->onboarded_at, 403, 'This account cannot access staff tools.');
                DB::table('staff_sessions')->upsert([
                    'user_id' => $user->id,
                    'session_hash' => self::session(),
                    'expires_at' => now()->addMinutes(15)->toDateTimeString(),
                    'created_at' => now()->toDateTimeString(),
                ], ['session_hash'], ['expires_at']);
            }

            AuditTrail::record('account.reauthenticated', ['purpose' => $purpose], $user->id);

            return [
                'proof' => self::issue($user->id, $purpose, 300, self::session()),
                'purpose' => $purpose,
                'expires_at' => now()->addMinutes(5),
                'staff_access_expires_at' => $purpose === 'staff' ? now()->addMinutes(15) : null,
            ];
        }));
    }

    public static function enrollStaff(User $user, string $role, string $secret, string $code): void
    {
        abort_unless(in_array($role, ['moderator', 'admin'], true), 422, 'Invalid staff role.');
        abort_unless($user->status === 'active' && $user->hasVerifiedEmail() && $user->onboarded_at, 422, 'Staff must have an active verified adult account.');
        $counter = Totp::counter($secret, $code);
        abort_unless($counter !== null, 422, 'Authenticator enrollment failed.');

        DB::transaction(function () use ($user, $role, $secret, $counter): void {
            DB::table('staff_accounts')->upsert([
                'user_id' => $user->id,
                'role' => $role,
                'mfa_secret' => Hash::encrypt($secret),
                'last_counter' => $counter,
                'enabled' => true,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ], ['user_id'], ['role', 'mfa_secret', 'last_counter', 'enabled', 'updated_at']);
            DB::table('staff_sessions')->where('user_id', $user->id)->delete();
            AuditTrail::record('staff.enrolled', ['user_id' => $user->id, 'role' => $role], null, 'console');
        });
    }

    public static function revokeStaff(int $userId): void
    {
        DB::transaction(function () use ($userId): void {
            DB::table('staff_accounts')->where('user_id', $userId)->update(['enabled' => false, 'updated_at' => now()->toDateTimeString()]);
            DB::table('staff_sessions')->where('user_id', $userId)->delete();
            AuditTrail::record('staff.revoked', ['user_id' => $userId], null, 'console');
        });
    }
}
