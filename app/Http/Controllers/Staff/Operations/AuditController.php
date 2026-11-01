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
        $filters = ListFilters::read($request, ['actor_type' => ['staff', 'user', 'system', 'console']]);
        $query = DB::table('audit_events')->select(['id', 'actor_id', 'actor_type', 'action', 'context', 'created_at']);
        if ($context->membership->role !== 'admin') {
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
        if ($filters['q'] !== '') {
            $query->where('action', $filters['q']);
        }

        return Inertia::render('operations/audit', Records::listing($query, $filters, function ($row): array {
            $context = json_decode($row->context, true) ?: [];
            $safe = [];
            foreach (['report_id', 'appeal_id', 'adjustment_id', 'operation_id', 'target_id', 'export_id', 'member_id', 'boost_id', 'placement_id', 'enrollment_id', 'observation_id', 'request_id', 'version'] as $key) {
                if (isset($context[$key]) && is_numeric($context[$key])) {
                    $safe[] = Records::label($key) . ': ' . (int) $context[$key];
                }
            }

            return Records::row($row, $row->action, null, $row->actor_type,
                'Actor ' . ($row->actor_id ? '#' . $row->actor_id : 'removed/system') . ($safe ? ' · ' . implode(' · ', $safe) : ''));
        }, 'staff.audit', ['actor_type' => ['staff', 'user', 'system', 'console']], true) + [
            'page' => ['title' => 'Audit history', 'breadcrumbs' => []],
            'description' => 'Append-only events within your domain permissions. Search an exact action name; sensitive context is omitted.',
        ]);
    }
}
