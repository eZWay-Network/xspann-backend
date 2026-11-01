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

        foreach ([
            ['reports.view', 'reports', 'open', 'staff.reports.index', 'Open reports', 'Reported accounts and content await review.'],
            ['appeals.view', 'moderation_appeals', 'open', 'staff.appeals.index', 'Open appeals', 'Independent reviewers are needed for challenged decisions.'],
            ['boosts.view', 'promotion_boosts', 'pending_review', 'staff.boosts.index', 'Campaigns awaiting review', 'Review creative and rights before the requested start.'],
            ['placements.view', 'promotion_placements', 'awaiting_evidence', 'staff.placements.index', 'Delivery evidence needed', 'Record distinct evidence for each completed obligation.'],
            ['privacy.view', 'privacy_requests', 'failed', 'staff.privacy.index', 'Failed privacy requests', 'Inspect the safe failure status and retry after resolving the cause.'],
        ] as [$ability, $table, $status, $route, $title, $description]) {
            if (!$context->can($ability)) {
                continue;
            }
            $query = DB::table($table)->where('status', $status);
            if (str_starts_with($table, 'promotion_')) {
                $query->where('environment', config('billing.environment'));
            }
            $count = $query->count();
            if ($count) {
                $items[] = ['key' => $table, 'title' => $title, 'description' => $description,
                    'count' => $count, 'severity' => 'warning', 'url' => (string) route($route)->withQuery(['status' => $status])];
            }
        }

        return array_map(fn(array $item) => $item + ['observed_at' => Sessions::iso(Sessions::timestamp())], $items);
    }
}
