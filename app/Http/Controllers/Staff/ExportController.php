<?php

namespace App\Http\Controllers\Staff;

use App\Http\Requests\Staff\{ExportRequest, ListFilters};
use App\Http\Resources\Staff\Records;
use App\Services\AuditTrail;
use App\Services\Staff\{Context, Exports, Sessions};
use Inertia\Facades\Inertia;
use Spark\Facades\{DB, Storage};
use Spark\Http\{Request, Response};

final class ExportController
{
    public function index(Request $request): Response
    {
        $context = Context::from($request);
        $types = array_keys(array_filter(Exports::SOURCES, fn ($ability) => $context->can($ability)));
        $filters = ListFilters::read($request, ['type' => $types, 'status' => ['queued', 'ready', 'failed', 'cancelled', 'expired']]);
        $query = DB::table('staff_exports')->select(['id', 'type', 'status', 'created_at', 'expires_at'])
            ->where('actor_id', $context->user->id)->where('membership_version', $context->membership->version)
            ->where('environment', config('billing.environment'))->whereIn('type', $types ?: ['none']);
        if ($filters['type']) {
            $query->where('type', $filters['type']);
        }
        if ($filters['status'] === 'expired') {
            $query->where('expires_at', '<=', Sessions::timestamp());
        } elseif ($filters['status']) {
            $query->where('status', $filters['status'])->where('expires_at', '>', Sessions::timestamp());
        }
        if ($filters['q']) {
            $query->where('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0);
        }

        return Inertia::render('exports/index', Records::listing($query, $filters, fn ($row) => Records::row($row, ucfirst($row->type) . ' report #' . $row->id,
            (string) route('staff.exports.show', ['export' => $row->id]), $row->expires_at <= Sessions::timestamp() ? 'expired' : $row->status, null, 'UTC'),
            'staff.exports.index', ['type' => $types, 'status' => ['queued', 'ready', 'failed', 'cancelled', 'expired']]) + [
                'page' => ['title' => 'My report exports', 'breadcrumbs' => []],
                'description' => 'Private aggregate reports created by you. Downloads expire after 24 hours and require your current source permissions.',
                'types' => $types, 'store_url' => (string) route('staff.exports.store'),
            ]);
    }

    public function store(ExportRequest $request): Response
    {
        $id = Exports::create(Context::from($request), $request->validated()->toArray());
        Inertia::flash('success', 'Report export queued.');

        return Inertia::redirect((string) route('staff.exports.show', ['export' => $id]), 303);
    }

    public function show(Request $request, int $export): Response
    {
        $row = Exports::owned(Context::from($request), $export);
        if ($row->expires_at <= Sessions::timestamp()) {
            $row->status = 'expired';
        }
        $operation = DB::table('staff_operations')->where('type', 'export.generate')->where('target_id', $export)->value('id');

        return Inertia::render('exports/show', [
            'page' => ['title' => 'Report export #' . $export, 'breadcrumbs' => []],
            'fields' => Records::fields($row, ['id', 'type', 'environment', 'status', 'row_count', 'error_code', 'created_at', 'expires_at'], 'UTC'),
            'filters' => json_decode($row->filters, true),
            'download_url' => $row->status === 'ready' && $row->expires_at > Sessions::timestamp() ? (string) route('staff.exports.download', ['export' => $export]) : null,
            'links' => $operation ? [['label' => 'View generation outcome', 'url' => (string) route('staff.operations.show', ['operation' => $operation])]] : [],
            'refresh_url' => (string) route('staff.exports.show', ['export' => $export]),
        ]);
    }

    public function download(Request $request, int $export): Response
    {
        $context = Context::from($request);
        $row = Exports::owned($context, $export);
        abort_unless($row->status === 'ready' && $row->expires_at > Sessions::timestamp(), 410);
        $disk = Storage::disk('local');
        abort_unless($disk->exists(Exports::path($export)), 404);
        AuditTrail::record('staff.export.downloaded', ['export_id' => $export, 'type' => $row->type], $context->user->id, 'staff');

        return response($disk->get(Exports::path($export)), 200)->withHeaders([
            'Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="xpn-report-' . $export . '.csv"',
        ]);
    }
}
