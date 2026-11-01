<?php

namespace App\Http\Controllers\Staff\Operations;

use App\Http\Requests\Staff\{ListFilters, RecoveryRequest};
use App\Http\Resources\Staff\Records;
use App\Services\Staff\{Context, Operations, Recovery};
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class MediaController
{
    public function index(Request $request): Response
    {
        $choices = ['kind' => ['video', 'audio'], 'status' => ['failed', 'processing']];
        $filters = ListFilters::read($request, $choices);
        $filters['kind'] = $filters['kind'] ?: 'video';
        $video = $filters['kind'] === 'video';
        $columns = $video ? ['id', 'status', 'processing_state', 'moderation_status', 'created_at', 'updated_at']
            : ['id', 'status', 'origin', 'rights_confirmed_at', 'moderation_status', 'created_at', 'updated_at'];
        $query = DB::table($video ? 'videos' : 'audios')->select($columns)
            ->whereIn($video ? 'processing_state' : 'status', ['failed', 'processing'])->where('status', '!=', 'deleted');
        if ($filters['status']) {
            $query->where($video ? 'processing_state' : 'status', $filters['status']);
        }
        if ($filters['q'] !== '') {
            $query->where('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0);
        }
        $context = Context::from($request);

        return Inertia::render('operations/media', Records::listing($query, $filters, function ($row) use ($video, $context): array {
            $kind = $video ? 'video' : 'audio';

            return Records::row($row, ucfirst($kind) . ' #' . $row->id, null, $video ? $row->processing_state : $row->status)
                + ['recovery' => $context->can('media.retry') && Recovery::eligible('media.' . $kind, $row)
                    ? ['label' => 'Retry encoding', 'url' => (string) route('staff.media.' . $kind . '.retry', ['record' => $row->id]), 'key' => bin2hex(random_bytes(16))] : null];
        }, 'staff.media', $choices) + [
            'page' => ['title' => 'Media processing', 'breadcrumbs' => []],
            'description' => 'Failed or processing media. Processing must be stale for an hour before retry; private files are never exposed.',
            'cleanup' => array_values(array_filter(\App\Services\Staff\Heartbeats::read(), fn ($row) => $row['task'] === 'uploads')),
        ]);
    }

    public function video(RecoveryRequest $request, int $record): Response
    {
        return $this->retry($request, 'media.video', $record);
    }

    public function audio(RecoveryRequest $request, int $record): Response
    {
        return $this->retry($request, 'media.audio', $record);
    }

    private function retry(RecoveryRequest $request, string $type, int $id): Response
    {
        $operation = Operations::enqueue(Context::from($request), $type, $id, $request->validated()->toArray());
        Inertia::flash('success', 'Media retry queued. Encoding progress is tracked here.');

        return Inertia::redirect((string) route('staff.operations.show', ['operation' => $operation]), 303);
    }
}
