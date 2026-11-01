<?php

namespace App\Http\Controllers\Staff;

use App\Http\Requests\Staff\ListFilters;
use App\Http\Resources\Staff\Records;
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class AudioController
{
    private const COLUMNS = ['id', 'user_id', 'title', 'origin', 'status', 'source_video_id', 'duration', 'reuse_allowed', 'rights_version', 'rights_confirmed_at', 'moderation_status', 'created_at'];
    private const FILTERS = ['status' => ['pending', 'processing', 'ready', 'failed', 'deleted'], 'moderation_status' => ['approved', 'restricted']];

    public function index(Request $request): Response
    {
        $filters = ListFilters::read($request, self::FILTERS + ['owner_id' => []]);
        $query = DB::table('audios')->select(self::COLUMNS);
        foreach (array_keys(self::FILTERS) as $key) {
            if (!empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (!empty($filters['owner_id'])) {
            $query->where('user_id', $filters['owner_id']);
        }
        if ($filters['q'] !== '') {
            $query->where(fn ($query) => $query->whereContains('title', $filters['q'])->orWhere('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0));
        }

        return Inertia::render('content/audios/index', Records::listing($query, $filters,
            fn ($row) => Records::row($row, $row->title ?: 'Audio #' . $row->id,
                (string) route('staff.audios.show', ['audio' => $row->id]), $row->moderation_status, 'user_id #' . $row->user_id),
            'staff.audios.index', self::FILTERS) + ['page' => ['title' => 'Audios', 'breadcrumbs' => []],
                'description' => 'Content metadata and rights declarations. Private playback requires an authorized review case.']);
    }

    public function show(Request $request, int $audio): Response
    {
        $id = $audio;
        $context = Context::from($request);
        $row = DB::table('audios')->select(self::COLUMNS)->where('id', $id)->firstOrFail();
        $fields = Records::fields($row, self::COLUMNS);
        $links = [];

        $fields[] = ['label' => 'Affected videos', 'value' => DB::table('videos')->where('audio_id', $id)->count()];
        if ($row->source_video_id) {
            $links[] = ['label' => 'Source video #' . $row->source_video_id, 'url' => (string) route('staff.videos.show', ['video' => $row->source_video_id])];
        }

        if ($context->can('reports.view')) {
            foreach (DB::table('reports')->where('target_type', 'audio')->where('target_id', $id)->select(['id', 'status'])->orderBy('id', 'desc')->limit(20)->get() as $case) {
                $links[] = ['label' => 'Case #' . $case->id . ' · ' . $case->status, 'url' => (string) route('staff.reports.show', ['report' => $case->id])];
            }
        }

        return Inertia::render('content/audios/show', ['page' => ['title' => 'Audio #' . $id, 'breadcrumbs' => []],
            'fields' => $fields, 'links' => $links,
            'case_target' => $context->can('content.case.create') && (int) $row->user_id !== (int) $context->user->id
                && \App\Models\Audio::availableTo(null)->whereKey($id)->exists()
                    ? ['type' => 'audio', 'id' => $id, 'url' => (string) route('staff.cases.create')] : null]);
    }
}
