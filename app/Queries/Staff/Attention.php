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
            ['billing.view', 'billing_webhooks', 'failed', 'staff.billing.webhooks.index', 'Failed billing receipts', 'Review receipt status and queue a targeted retry.'],
            ['operations.view', 'audios', 'failed', 'staff.media', 'Failed audio processing', 'Inspect eligible processing failures.'],
            ['communications.view', 'announcements', 'queued', 'staff.communications', 'Pending announcements', 'Check delivery progress and consent-safe recovery.'],
            ['privacy.view', 'privacy_requests', 'failed', 'staff.privacy.index', 'Failed privacy requests', 'Inspect the safe failure status and retry after resolving the cause.'],
        ] as [$ability, $table, $status, $route, $title, $description]) {
            if (!$context->can($ability)) {
                continue;
            }
            $query = DB::table($table)->where('status', $status);
            if ((str_starts_with($table, 'promotion_') || str_starts_with($table, 'billing_'))) {
                $query->where('environment', config('billing.environment'));
            }
            $count = $query->count();
            if ($count) {
                $items[] = ['key' => $table, 'title' => $title, 'description' => $description,
                    'count' => $count, 'severity' => 'warning', 'url' => (string) route($route)->withQuery($table === 'audios' ? ['status' => $status, 'kind' => 'audio'] : ['status' => $status])];
            }
        }

        if ($context->can('billing.view')) {
            $count = \App\Services\Billing\Billing::table('adjustments')->where('allocation', 'review_required')->count();
            if ($count) {
                $items[] = ['key' => 'allocation', 'title' => 'Adjustments require allocation', 'description' => 'Review the provider record before changing its service effect.',
                    'count' => $count, 'severity' => 'warning', 'url' => (string) route('staff.billing.adjustments.index')->withQuery(['allocation' => 'review_required'])];
            }
        }
        if ($context->can('operations.view')) {
            foreach ([
                ['failed_videos', 'Failed video processing', DB::table('videos')->where('processing_state', 'failed')->where('status', '!=', 'deleted')->count(), 'staff.media', ['kind' => 'video', 'status' => 'failed']],
                ['due_releases', 'Scheduled releases are due', DB::table('videos')->where('publication_state', 'scheduled')->where('status', '!=', 'deleted')->where('publish_at', '<=', Sessions::timestamp())->count(), 'staff.releases.index', ['health' => 'due']],
            ] as [$key, $title, $count, $route, $filters]) {
                if ($count) {
                    $items[] = ['key' => $key, 'title' => $title, 'description' => 'Inspect eligibility and the latest worker outcome before recovery.',
                        'count' => $count, 'severity' => 'warning', 'url' => (string) route($route)->withQuery($filters)];
                }
            }
            $stale = count(array_filter(\App\Services\Staff\Heartbeats::read(), fn ($row) => $row['status'] !== 'observed'));
            if ($stale) {
                $items[] = ['key' => 'workers', 'title' => 'Worker observations need attention', 'description' => 'Some maintenance tasks are unknown, stale or failed.',
                    'count' => $stale, 'severity' => 'warning', 'url' => (string) route('staff.operations.overview')];
            }
        }
        $failed = DB::table('staff_operations')->where('actor_id', $context->user->id)->where('environment', config('billing.environment'))
            ->where('membership_version', $context->membership->version)->where('status', 'failed')->orderBy('id', 'desc')->limit(5)->get();
        foreach ($failed as $operation) {
            $ability = \App\Services\Staff\Operations::TYPES[$operation->type][1] ?? 'invalid';
            if ($context->can($ability)) {
                $items[] = ['key' => 'operation:' . $operation->id, 'title' => 'Your recovery action failed', 'description' => 'Review the recorded outcome before requesting further recovery.',
                    'count' => 1, 'severity' => 'warning', 'url' => (string) route('staff.operations.show', ['operation' => $operation->id])];
            }
        }

        return array_map(fn(array $item) => $item + ['observed_at' => Sessions::iso(Sessions::timestamp())], $items);
    }
}
