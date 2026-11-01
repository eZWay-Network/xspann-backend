<?php

namespace App\Http\Controllers\Staff\Operations;

use App\Http\Resources\Staff\Records;
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class ActionController
{
    public function show(Request $request, int $operation): Response
    {
        $context = Context::from($request);
        $row = DB::table('staff_operations')->where('id', $operation)->where('environment', config('billing.environment'))->firstOrFail();
        abort_unless($row->type === 'privacy.retry' && $context->can('privacy.view')
            && ((int) $row->actor_id === (int) $context->user->id || $context->can('privacy.retry')), 403);

        return Inertia::render('operations/action', [
            'page' => ['title' => 'Operation #' . $operation, 'breadcrumbs' => []],
            'fields' => Records::fields($row, ['id', 'type', 'status', 'result_code', 'created_at', 'started_at', 'completed_at', 'expires_at'], 'UTC'),
            'status' => $row->status,
            'refresh_url' => (string) route('staff.operations.show', ['operation' => $operation]),
            'target_url' => (string) route('staff.privacy.show', ['privacyRequest' => $row->target_id]),
        ]);
    }
}
