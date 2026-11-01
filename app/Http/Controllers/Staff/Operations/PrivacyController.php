<?php

namespace App\Http\Controllers\Staff\Operations;

use App\Http\Requests\Staff\{ListFilters, PrivacyRetryRequest};
use App\Http\Resources\Staff\Records;
use App\Services\Staff\{Context, Operations};
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class PrivacyController
{
    private const COLUMNS = ['id', 'reference', 'kind', 'status', 'attempts', 'error_code', 'expires_at', 'completed_at', 'created_at', 'updated_at'];

    public function index(Request $request): Response
    {
        $choices = ['kind' => ['export', 'deletion'], 'status' => ['pending', 'processing', 'failed', 'ready', 'completed', 'expired']];
        $filters = ListFilters::read($request, $choices);
        $query = DB::table('privacy_requests')->select(self::COLUMNS);
        foreach (['kind', 'status'] as $key) {
            if (!empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if ($filters['q'] !== '') {
            $query->where('reference', $filters['q']);
        }

        return Inertia::render('operations/privacy/index', Records::listing($query, $filters,
            fn ($row) => Records::row($row, ucfirst($row->kind) . ' #' . $row->id, (string) route('staff.privacy.show', ['privacyRequest' => $row->id]), $row->status, $row->reference),
            'staff.privacy.index', $choices) + ['page' => ['title' => 'Privacy requests', 'breadcrumbs' => []],
                'description' => 'Inspect request progress and retry failed work. Export files remain accessible only to their owner.']);
    }

    public function show(Request $request, int $privacyRequest): Response
    {
        $row = DB::table('privacy_requests')->select(self::COLUMNS)->where('id', $privacyRequest)->firstOrFail();
        $context = Context::from($request);

        return Inertia::render('operations/privacy/show', [
            'page' => ['title' => 'Privacy request #' . $privacyRequest, 'breadcrumbs' => []],
            'fields' => Records::fields($row, self::COLUMNS),
            'retry_url' => $row->status === 'failed' && $context->can('privacy.retry') ? (string) route('staff.privacy.retry', ['privacyRequest' => $privacyRequest]) : null,
            'idempotency_key' => bin2hex(random_bytes(16)),
        ]);
    }

    public function retry(PrivacyRetryRequest $request, int $privacyRequest): Response
    {
        $id = Operations::privacyRetry(Context::from($request), $privacyRequest, $request->validated()->toArray());
        Inertia::flash('success', 'Privacy retry queued. Follow its recorded outcome here.');

        return Inertia::redirect((string) route('staff.operations.show', ['operation' => $id]), 303);
    }
}
