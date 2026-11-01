<?php

namespace App\Services\Staff;

use App\Models\User;
use App\Services\AuditTrail;
use Spark\Facades\{Auth, DB, Session};
use Spark\Http\Request;

final class Sessions
{
    public static function timestamp(int $offset = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offset);
    }

    public static function iso(?string $value): ?string
    {
        return $value === null ? null : str_replace(' ', 'T', $value) . 'Z';
    }

    public static function eligible(?User $user): bool
    {
        return $user && $user->status === 'active' && $user->hasVerifiedEmail() && $user->onboarded_at;
    }

    public static function hash(): ?string
    {
        $nonce = Session::get('staff_nonce');
        $binding = Session::get('staff_session_binding');

        if (!is_string($nonce) || strlen($nonce) !== 64 || !is_string($binding) || !hash_equals($binding, hash('sha256', Session::id()))) {
            return null;
        }

        return hash('sha256', 'xpn.staff.browser:' . $nonce . ':' . $binding);
    }

    public static function resolve(Request $request, bool $touch = true): ?Context
    {
        // Resolve the named guard afresh on every request, including long-lived workers.
        $user = $request->user(guard: 'staff_web');
        $hash = self::hash();

        if (!self::eligible($user) || $hash === null) {
            return null;
        }

        $member = DB::table('staff_accounts')->where('user_id', $user->id)->where('enabled', true)->first();
        $record = DB::table('staff_sessions')->where('user_id', $user->id)->where('session_hash', $hash)
            ->whereNotNull('membership_version')->whereNull('revoked_at')
            ->where('expires_at', '>', self::timestamp())->where('absolute_expires_at', '>', self::timestamp())->first();

        if (!$member || !$record || (int) $member->version !== (int) $record->membership_version || !Permissions::forRole($member->role)) {
            return null;
        }

        if ($touch) {
            $record->expires_at = min(self::timestamp(config('staff.idle_seconds')), $record->absolute_expires_at);
            DB::table('staff_sessions')->where('id', $record->id)->whereNull('revoked_at')->update([
                'last_activity_at' => self::timestamp(),
                'expires_at' => $record->expires_at,
            ]);
        }

        return new Context($user, $member, $record);
    }

    public static function requireCurrent(Context $context): Context
    {
        $member = DB::table('staff_accounts')->where('user_id', $context->user->id)->where('enabled', true)->lockForUpdate()->first();
        $record = DB::table('staff_sessions')->where('id', $context->session->id)->whereNull('revoked_at')
            ->where('expires_at', '>', self::timestamp())->where('absolute_expires_at', '>', self::timestamp())
            ->where('mfa_expires_at', '>', self::timestamp())->lockForUpdate()->first();
        $user = User::where('id', $context->user->id)->lockForUpdate()->first();

        if (!$member || !$record || !self::eligible($user) || (int) $member->version !== (int) $context->membership->version
            || (int) $record->membership_version !== (int) $member->version || !Permissions::forRole($member->role)) {
            throw new StaffException('Your staff access has changed. Please sign in again.', 403);
        }

        return new Context($user, $member, $record);
    }

    public static function establish(User $user, object $member, Request $request): void
    {
        self::revokeCurrent();
        Auth::guard('staff_web')->login($user);
        Session::regenerate(true);
        Session::forget('staff_pending');
        Session::forget('staff_enrollment');
        Session::put('staff_nonce', bin2hex(random_bytes(32)));
        Session::put('staff_session_binding', hash('sha256', Session::id()));
        Session::put('csrf_token', bin2hex(random_bytes(32)));
        Session::put('staff_clear_history', true);

        DB::table('staff_sessions')->insert([
            'user_id' => $user->id,
            'session_hash' => self::hash(),
            'membership_version' => (int) $member->version,
            'authenticated_at' => self::timestamp(),
            'mfa_at' => self::timestamp(),
            'mfa_expires_at' => self::timestamp(config('staff.mfa_seconds')),
            'last_activity_at' => self::timestamp(),
            'expires_at' => self::timestamp(config('staff.idle_seconds')),
            'absolute_expires_at' => self::timestamp(config('staff.absolute_seconds')),
            'device_label' => self::device($request->useragent() ?? ''),
            'created_at' => self::timestamp(),
        ]);
        AuditTrail::record('staff.browser_login', [], $user->id, 'staff');
    }

    public static function refreshMfa(Context $context): void
    {
        $updated = DB::table('staff_sessions')->where('id', $context->session->id)->whereNull('revoked_at')->update([
            'mfa_at' => self::timestamp(),
            'mfa_expires_at' => self::timestamp(config('staff.mfa_seconds')),
        ]);

        if ($updated !== 1) {
            throw new StaffException('This session has been revoked. Please sign in again.', 401);
        }
        Session::put('staff_clear_history', true);
    }

    public static function revokeAll(int $userId): void
    {
        // One registry is also used by legacy API tests until the coordinated Step 2 move.
        DB::table('staff_sessions')->where('user_id', $userId)->delete();
    }

    public static function revokeCurrent(): void
    {
        if ($hash = self::hash()) {
            DB::table('staff_sessions')->where('session_hash', $hash)->update(['revoked_at' => self::timestamp()]);
        }
    }

    public static function logout(): void
    {
        self::revokeCurrent();
        Auth::guard('staff_web')->logout();
        Session::invalidate();
        Session::put('csrf_token', bin2hex(random_bytes(32)));
        Session::put('staff_clear_history', true);
    }

    public static function maintain(): void
    {
        // Legacy API grants use the established app timezone; browser dates are UTC.
        DB::table('staff_sessions')->whereNull('membership_version')->where('expires_at', '<=', now()->toDateTimeString())->delete();
        DB::table('staff_sessions')->whereNotNull('membership_version')
            ->where(fn ($query) => $query->where('expires_at', '<=', self::timestamp())
                ->orWhere('absolute_expires_at', '<=', self::timestamp())->orWhereNotNull('revoked_at'))->delete();
        DB::table('staff_invitations')->whereNotNull('active_key')->where('expires_at', '<=', self::timestamp())
            ->update(['active_key' => null, 'updated_at' => self::timestamp()]);
        DB::table('staff_invitations')->whereNull('active_key')->where('updated_at', '<', self::timestamp(-30 * 86400))->delete();
    }

    public static function destination(?string $value): string
    {
        if (!is_string($value) || !str_starts_with($value, '/') || str_starts_with($value, '//')
            || preg_match('/[\\\\\x00-\x20]/', rawurldecode($value))) {
            return '/';
        }
        $path = rtrim((string) parse_url($value, PHP_URL_PATH), '/') ?: '/';

        // Return only to a registered, protected GET page, never an API or auth action.
        foreach (app(\Spark\Http\Routing\Router::class)->getRoutes() as $name => $route) {
            if (is_string($name) && str_starts_with($name, 'staff.')
                && $route['method'] === Request::METHOD_GET && in_array('staff.web', (array) $route['middleware'], true)
                && (rtrim($route['path'], '/') ?: '/') === $path) {
                return $value;
            }
        }

        return '/';
    }

    private static function device(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Browser',
        };

        return $browser . (preg_match('/Mobile|Android|iPhone/', $agent) ? ' on mobile' : ' on desktop');
    }
}
