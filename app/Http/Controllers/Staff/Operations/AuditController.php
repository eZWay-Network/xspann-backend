<?php

namespace App\Http\Controllers\Staff\Operations;

use App\Http\Requests\Staff\ListFilters;
use App\Http\Resources\Staff\Records;
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class AuditController
{
    public function index(Request $request): Response
    {
        $context = Context::from($request);
        $choices = ['actor_type' => ['staff', 'user', 'system', 'console'], 'target_type' => array_keys(\App\Services\Moderation::TABLES)];
        $filters = ListFilters::read($request, $choices + ['actor_id' => [], 'affected_user_id' => [], 'target_id' => [], 'report_id' => [], 'action' => [], 'from' => [], 'to' => []]);
        $query = DB::table('audit_events')->select(['id', 'actor_id', 'actor_type', 'action', 'context', 'created_at']);
        if ($context->membership->role !== 'super_admin') {
            $query->where(function ($query) use ($context): void {
                $query->where('actor_id', $context->user->id);
                foreach ([
                    'billing.view' => ['billing.%'],
                    'reports.view' => ['moderation.%'],
                    'privacy.view' => ['privacy.%'],
                    'boosts.view' => ['promotion.boost.%'],
                    'placements.view' => ['promotion.placement.%', 'promotion.pilot.%', 'promotion.extension.%'],
                    'promotion.surface.view' => ['promotion.surface.%'],
                ] as $ability => $prefixes) {
                    if ($context->can($ability)) {
                        foreach ($prefixes as $prefix) {
                            $query->orWhere('action', 'LIKE', $prefix);
                        }
                    }
                }
                $types = array_keys(array_filter(\App\Services\Staff\Operations::TYPES, fn ($definition) => $context->can($definition[1])));
                $types = array_diff($types, ['export.generate']);
                if ($types) {
                    $query->orWhere(function ($query) use ($types): void {
                        $query->whereIn('action', ['staff.operation.queued', 'staff.operation.finished'])->where(function ($query) use ($types): void {
                            foreach ($types as $type) {
                                $query->orWhere('context', 'LIKE', '%"type":"' . $type . '"%');
                            }
                        });
                    });
                }
            });
        }
        if ($filters['actor_type']) {
            $query->where('actor_type', $filters['actor_type']);
        }
        foreach (['actor_id', 'affected_user_id', 'target_id', 'target_type', 'report_id'] as $field) {
            if ($filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }
        if ($filters['action'] !== '') {
            $query->where(fn ($query) => $query->where('action', $filters['action'])
                ->orFindInJson('context', 'action', $filters['action']));
        }
        foreach (['from' => '>=', 'to' => '<'] as $field => $operator) {
            if ($filters[$field] !== '') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $filters[$field], new \DateTimeZone($context->preferences()['timezone']));
                abort_unless($date && $date->format('Y-m-d') === $filters[$field], 422, 'Choose a valid date.');
                if ($field === 'to') {
                    $date = $date->modify('+1 day');
                }
                $query->where('created_at', $operator, $date->setTimezone(new \DateTimeZone(config('app.timezone', 'UTC')))->format('Y-m-d H:i:s'));
            }
        }
        abort_if($filters['from'] && $filters['to'] && $filters['from'] > $filters['to'], 422, 'The date range is reversed.');
        if ($filters['q'] !== '') {
            $query->whereContains('action', $filters['q']);
        }

        return Inertia::render('operations/audit', Records::listing($query, $filters, function ($row): array {
            $context = json_decode($row->context, true) ?: [];
            $safe = [];
            foreach (['report_id', 'appeal_id', 'adjustment_id', 'operation_id', 'target_id', 'export_id', 'member_id', 'boost_id', 'placement_id', 'enrollment_id', 'observation_id', 'request_id', 'version'] as $key) {
                if (isset($context[$key]) && is_numeric($context[$key])) {
                    $safe[] = Records::label($key) . ': ' . (int) $context[$key];
                }
            }

            $decisionDetails = str_starts_with($row->action, 'moderation.') ? array_intersect_key($context,
                array_flip(['action', 'outcome', 'reason_code', 'reason', 'before', 'after', 'expires_at'])) : [];
            return ['audit' => $decisionDetails] + Records::row($row, $row->action . (isset($context['action']) ? ' · ' . $context['action'] : ''), null, $row->actor_type,
                'Actor ' . ($row->actor_id ? '#' . $row->actor_id : 'removed/system') . ($safe ? ' · ' . implode(' · ', $safe) : ''));
        }, 'staff.audit', $choices, true) + [
            'page' => ['title' => 'Audit history', 'breadcrumbs' => []],
            'description' => 'Read-only audit history. Filter by staff, affected user, content, report, action and date.',
        ]);
    }
}
