<?php

namespace App\Services\Staff;

use App\Models\User;
use App\Services\{AuditTrail, Totp};
use Spark\Facades\{Cache, DB, Hash};

final class Team
{
    public static function locked(callable $callback): mixed
    {
        return Cache::lock('staff.memberships', 20)->block(5, fn () => DB::transaction($callback));
    }

    public static function requireFresh(Context $context): void
    {
        $context = Sessions::requireCurrent($context);
        $context->authorize('staff.manage');

        if (!$context->recent(config('staff.confirmation_seconds'))) {
            throw new StaffException('Confirm your identity again before changing staff access.', 422, 'confirmation');
        }
    }

    public static function protectLastAdmin(object $member, string $role, bool $enabled): void
    {
        if ($member->role !== 'admin' || !$member->enabled || ($role === 'admin' && $enabled)) {
            return;
        }
        $other = DB::table('staff_accounts')->where('role', 'admin')->where('enabled', true)
            ->where('user_id', '!=', $member->user_id)->lockForUpdate()->first();

        if (!$other) {
            throw new StaffException('Keep at least one enabled administrator. Enroll another administrator first.', 409);
        }
    }

    public static function invite(Context $context, string $email, string $role, string $reason): string
    {
        return self::locked(function () use ($context, $email, $role, $reason): string {
            self::requireFresh($context);
            $user = User::where('email', mb_strtolower(trim($email)))->first();

            if (!Sessions::eligible($user) || $user->id === $context->user->id) {
                throw new StaffException('Choose another active, verified, onboarded account.', 422, 'email');
            }
            $member = DB::table('staff_accounts')->where('user_id', $user->id)->lockForUpdate()->first();

            if ($member && $member->enabled) {
                throw new StaffException('This account is already staff. Use its role action instead.', 422, 'email');
            }
            DB::table('staff_invitations')->where('user_id', $user->id)->whereNotNull('active_key')->update([
                'active_key' => null, 'revoked_at' => Sessions::timestamp(), 'updated_at' => Sessions::timestamp(),
            ]);
            $token = bin2hex(random_bytes(32));
            DB::table('staff_invitations')->insert([
                'user_id' => $user->id,
                'inviter_id' => $context->user->id,
                'role' => $role,
                'membership_version' => (int) ($member->version ?? 0),
                'inviter_version' => (int) $context->membership->version,
                'token_hash' => hash('sha256', $token),
                'active_key' => 'staff:' . $user->id,
                'expires_at' => Sessions::timestamp(config('staff.invitation_seconds')),
                'created_at' => Sessions::timestamp(),
                'updated_at' => Sessions::timestamp(),
            ]);
            AuditTrail::record('staff.invited', ['user_id' => $user->id, 'role' => $role, 'reason' => trim($reason)], $context->user->id, 'staff');

            return (string) route('staff.enroll.link', ['token' => $token]);
        });
    }

    public static function invitation(string $hash): object
    {
        $invitation = DB::table('staff_invitations')->where('token_hash', $hash)->whereNotNull('active_key')
            ->whereNull('consumed_at')->whereNull('revoked_at')->where('expires_at', '>', Sessions::timestamp())->lockForUpdate()->first();
        $inviter = $invitation ? DB::table('staff_accounts')->where('user_id', $invitation->inviter_id)->where('enabled', true)->first() : null;
        $member = $invitation ? DB::table('staff_accounts')->where('user_id', $invitation->user_id)->first() : null;

        if (!$invitation || !$inviter || !Sessions::eligible(User::find($invitation->inviter_id))
            || !Permissions::allows($inviter->role, 'staff.manage') || (int) $inviter->version !== (int) $invitation->inviter_version
            || (int) ($member->version ?? 0) !== (int) $invitation->membership_version || ($member && $member->enabled)
            || !in_array($invitation->role, Permissions::roles(), true)) {
            throw new StaffException('This invitation is unavailable or expired. Ask an administrator for a new invitation.', 403);
        }

        return $invitation;
    }

    public static function accept(string $hash, string $secret, string $code, int $expectedUser, string $credentialVersion): User
    {
        return self::locked(function () use ($hash, $secret, $code, $expectedUser, $credentialVersion): User {
            $invitation = self::invitation($hash);
            $user = User::find($invitation->user_id);

            if (!Sessions::eligible($user) || $user->id !== $expectedUser || !hash_equals($credentialVersion, BrowserAuth::credentialVersion($user))) {
                throw new StaffException('This invitation cannot be accepted by this account.', 403);
            }
            $counter = Totp::counter($secret, $code);

            if ($counter === null) {
                throw new StaffException('Enter the code from the authenticator you just set up.', 422, 'mfa_code');
            }
            DB::table('staff_accounts')->upsert([
                'user_id' => $user->id,
                'role' => $invitation->role,
                'mfa_secret' => Hash::encrypt($secret),
                'last_counter' => $counter,
                'enabled' => true,
                'version' => (int) $invitation->membership_version + 1,
                'created_at' => Sessions::timestamp(),
                'updated_at' => Sessions::timestamp(),
            ], ['user_id'], ['role', 'mfa_secret', 'last_counter', 'enabled', 'version', 'updated_at']);
            DB::table('staff_invitations')->where('id', $invitation->id)->update([
                'active_key' => null, 'consumed_at' => Sessions::timestamp(), 'updated_at' => Sessions::timestamp(),
            ]);
            Sessions::revokeAll($user->id);
            AuditTrail::record('staff.invitation_accepted', ['role' => $invitation->role, 'inviter_id' => (int) $invitation->inviter_id], $user->id, 'staff');

            return $user;
        });
    }

    public static function update(Context $context, int $id, array $input): void
    {
        self::locked(function () use ($context, $id, $input): void {
            self::requireFresh($context);
            $member = DB::table('staff_accounts')->where('id', $id)->lockForUpdate()->first();

            if (!$member) {
                throw new StaffException('Staff member not found.', 404);
            }

            if ((int) $member->version !== (int) $input['version']) {
                throw new StaffException('Staff access changed. Reload and review the current role before retrying.', 409);
            }
            $action = $input['action'];
            $role = $action === 'role' ? $input['role'] : $member->role;
            $enabled = $action === 'disable' ? false : (bool) $member->enabled;

            if ((int) $member->user_id === $context->user->id && $action === 'role') {
                throw new StaffException('You cannot assign your own role.', 403);
            }
            self::protectLastAdmin($member, $role, $enabled);
            DB::table('staff_accounts')->where('id', $id)->where('version', $member->version)->update([
                'role' => $role, 'enabled' => $enabled, 'version' => (int) $member->version + 1, 'updated_at' => Sessions::timestamp(),
            ]);
            Sessions::revokeAll((int) $member->user_id);
            AuditTrail::record('staff.' . $action, ['user_id' => (int) $member->user_id, 'role' => $role, 'reason' => trim($input['reason'])], $context->user->id, 'staff');
        });
    }

    public static function cancelInvitation(Context $context, int $id, string $reason): void
    {
        self::locked(function () use ($context, $id, $reason): void {
            self::requireFresh($context);
            $count = DB::table('staff_invitations')->where('id', $id)->whereNotNull('active_key')->update([
                'active_key' => null, 'revoked_at' => Sessions::timestamp(), 'updated_at' => Sessions::timestamp(),
            ]);

            if ($count !== 1) {
                throw new StaffException('This invitation is already closed. Reload the team page.', 409);
            }
            AuditTrail::record('staff.invitation_cancelled', ['invitation_id' => $id, 'reason' => trim($reason)], $context->user->id, 'staff');
        });
    }
}
