<?php

namespace App\Http\Controllers\Staff;

use App\Http\Requests\Staff\{ListFilters, RecoveryRequest};
use App\Http\Resources\Staff\Records;
use App\Services\Staff\{Context, Operations, Recovery};
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class ReleaseController
{
    private const COLUMNS = ['id', 'user_id', 'publication_state', 'processing_state', 'publication_intent', 'publish_at', 'publish_timezone', 'published_at', 'release_error', 'version', 'status', 'created_at'];

    public function index(Request $request): Response
    {
        $choices = ['state' => ['scheduled', 'draft', 'published'], 'health' => ['due', 'paused']];
        $filters = ListFilters::read($request, $choices);
        $query = DB::table('videos')->select(self::COLUMNS)->where('status', '!=', 'deleted');
        if ($filters['state']) {
            $query->where('publication_state', $filters['state']);
        }
        if ($filters['health'] === 'due') {
            $query->where('publication_state', 'scheduled')->where('publish_at', '<=', \App\Services\Staff\Sessions::timestamp());
        } elseif ($filters['health'] === 'paused') {
            $query->whereNotNull('release_error');
        }
        if ($filters['q'] !== '') {
            $query->where('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0);
        }

        return Inertia::render('releases/index', Records::listing($query, $filters,
            fn ($row) => Records::row($row, 'Release #' . $row->id, (string) route('staff.releases.show', ['record' => $row->id]), $row->publication_state, $row->release_error),
            'staff.releases.index', $choices) + ['page' => ['title' => 'Releases', 'breadcrumbs' => []],
                'description' => 'Scheduled publication and paused releases. Only the owner can reschedule a paused draft.']);
    }

    public function show(Request $request, int $record): Response
    {
        $row = DB::table('videos')->where('id', $record)->where('status', '!=', 'deleted')->firstOrFail();
        $context = Context::from($request);

        return Inertia::render('releases/show', [
            'page' => ['title' => 'Release #' . $record, 'breadcrumbs' => []],
            'fields' => Records::fields($row, array_diff(self::COLUMNS, ['created_at']), 'UTC'),
            'recovery' => $context->can('releases.recover') && Recovery::eligible('release.recover', $row)
                ? ['label' => 'Recover due release', 'url' => (string) route('staff.releases.recover', ['record' => $record])] : null,
            'idempotency_key' => bin2hex(random_bytes(16)),
        ]);
    }

    public function recover(RecoveryRequest $request, int $record): Response
    {
        $operation = Operations::enqueue(Context::from($request), 'release.recover', $record, $request->validated()->toArray());
        Inertia::flash('success', 'Due release recovery queued.');

        return Inertia::redirect((string) route('staff.operations.show', ['operation' => $operation]), 303);
    }
}
