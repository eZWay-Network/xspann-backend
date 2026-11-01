<?php

namespace App\Http\Controllers\Staff;

use App\Http\Requests\Staff\ListFilters;
use App\Http\Resources\Staff\Records;
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class VideoController
{
    private const COLUMNS = ['id', 'user_id', 'caption', 'status', 'processing_state', 'publication_state', 'visibility', 'moderation_status', 'audio_id', 'rights_version', 'rights_confirmed_at', 'publish_at', 'publish_timezone', 'published_at', 'release_error', 'version', 'created_at'];
    private const FILTERS = ['processing_state' => ['pending', 'processing', 'ready', 'failed'], 'publication_state' => ['draft', 'scheduled', 'published'], 'visibility' => ['public', 'followers', 'private'], 'moderation_status' => ['approved', 'restricted']];

    public function index(Request $request): Response
    {
        $filters = ListFilters::read($request, self::FILTERS + ['owner_id' => []]);
        $query = DB::table('videos')->select(self::COLUMNS);
        foreach (array_keys(self::FILTERS) as $key) {
            if (!empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (!empty($filters['owner_id'])) {
            $query->where('user_id', $filters['owner_id']);
        }
        if ($filters['q'] !== '') {
            $query->where(fn ($query) => $query->whereContains('caption', $filters['q'])->orWhere('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0));
        }

        return Inertia::render('content/videos/index', Records::listing($query, $filters,
            fn ($row) => Records::row($row, $row->caption ?: 'Video #' . $row->id,
                (string) route('staff.videos.show', ['video' => $row->id]), $row->moderation_status, 'user_id #' . $row->user_id),
            'staff.videos.index', self::FILTERS) + ['page' => ['title' => 'Videos', 'breadcrumbs' => []],
                'description' => 'Content metadata and rights declarations. Private playback requires an authorized review case.']);
    }

    public function show(Request $request, int $video): Response
    {
        $id = $video;
        $context = Context::from($request);
        $row = DB::table('videos')->select(self::COLUMNS)->where('id', $id)->firstOrFail();
        foreach (['publish_at', 'published_at'] as $column) {
            $row->$column = Records::date($row->$column, 'UTC');
        }
        $fields = Records::fields($row, self::COLUMNS);
        $links = [];

        $fields[] = ['label' => 'Dependent audio', 'value' => DB::table('audios')->where('source_video_id', $id)->count()];
        if ($row->audio_id) {
            $links[] = ['label' => 'Audio #' . $row->audio_id, 'url' => (string) route('staff.audios.show', ['audio' => $row->audio_id])];
        }

        if ($context->can('reports.view')) {
            foreach (DB::table('reports')->where('target_type', 'video')->where('target_id', $id)->select(['id', 'status'])->orderBy('id', 'desc')->limit(20)->get() as $case) {
                $links[] = ['label' => 'Case #' . $case->id . ' · ' . $case->status, 'url' => (string) route('staff.reports.show', ['report' => $case->id])];
            }
        }

        return Inertia::render('content/videos/show', ['page' => ['title' => 'Video #' . $id, 'breadcrumbs' => []],
            'fields' => $fields, 'links' => $links,
            'case_target' => $context->can('content.case.create') && (int) $row->user_id !== (int) $context->user->id
                && \App\Models\Video::published()->visibleTo(null)->whereKey($id)->exists()
                    ? ['type' => 'video', 'id' => $id, 'url' => (string) route('staff.cases.create')] : null]);
    }
}
