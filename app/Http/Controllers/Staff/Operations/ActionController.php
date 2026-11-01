<?php

namespace App\Http\Controllers\Staff\Operations;

use App\Http\Resources\Staff\Records;
use App\Services\Staff\{Context, Exports, Operations};
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class ActionController
{
    public function show(Request $request, int $operation): Response
    {
        $context = Context::from($request);
        $row = DB::table('staff_operations')->where('id', $operation)->where('environment', config('billing.environment'))->firstOrFail();
        $definition = Operations::TYPES[$row->type] ?? null;
        abort_unless($definition && $context->can($definition[1])
            && ((int) $row->actor_id === (int) $context->user->id || $context->can($definition[0])), 403);
        if ($row->type === 'export.generate') {
            Exports::owned($context, (int) $row->target_id);
        }
        $path = $definition[2];
        $target = str_ends_with($path, '/') ? $path . $row->target_id : $path;

        return Inertia::render('operations/action', [
            'page' => ['title' => 'Operation #' . $operation, 'breadcrumbs' => []],
            'fields' => Records::fields($row, ['id', 'type', 'status', 'result_code', 'created_at', 'started_at', 'completed_at', 'expires_at'], 'UTC'),
            'status' => $row->status,
            'refresh_url' => (string) route('staff.operations.show', ['operation' => $operation]),
            'target_url' => (string) url($target),
        ]);
    }
}
