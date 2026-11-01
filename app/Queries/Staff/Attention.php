<?php

namespace App\Queries\Staff;

use App\Services\Staff\{Context, Sessions};
use Spark\Facades\DB;

final class Attention
{
    public static function read(Context $context): array
    {
        $items = [];
        $activeSessions = DB::table('staff_sessions')->where('user_id', $context->user->id)->whereNotNull('membership_version')
            ->whereNull('revoked_at')->where('expires_at', '>', Sessions::timestamp())->where('absolute_expires_at', '>', Sessions::timestamp())->count();

        if ($activeSessions > 1) {
            $items[] = [
                'key' => 'sessions',
                'title' => 'Review your active sessions',
                'description' => "$activeSessions browsers currently have staff access. Revoke any session you do not recognize.",
                'count' => $activeSessions,
                'severity' => 'info',
                'url' => (string) route('staff.security'),
            ];
        }

        if ($context->can('staff.view')) {
            $pending = DB::table('staff_invitations')->whereNotNull('active_key')->where('expires_at', '>', Sessions::timestamp())->count();

            if ($pending > 0) {
                $items[] = [
                    'key' => 'invitations',
                    'title' => 'Staff invitations awaiting enrollment',
                    'description' => 'Invited colleagues must verify their credentials and set up an authenticator before access is enabled.',
                    'count' => $pending,
                    'severity' => 'info',
                    'url' => (string) route('staff.team'),
                ];
            }
        }

        return array_map(fn(array $item) => $item + ['observed_at' => Sessions::iso(Sessions::timestamp())], $items);
    }
}
