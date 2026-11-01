<?php

namespace App\Http\Controllers\Staff;

use App\Http\Requests\Staff\ListFilters;
use App\Http\Resources\Staff\Records;
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class PlaylistController
{
    private const COLUMNS = ['id', 'artist_id', 'title', 'description', 'genre_id', 'category', 'visibility', 'moderation_status', 'version', 'created_at'];
    private const FILTERS = ['visibility' => ['public', 'private'], 'moderation_status' => ['approved', 'restricted']];

    public function index(Request $request): Response
    {
        $filters = ListFilters::read($request, self::FILTERS + ['artist_id' => []]);
        $query = DB::table('artist_playlists')->select(self::COLUMNS);
        foreach (array_keys(self::FILTERS) as $key) {
            if (!empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (!empty($filters['artist_id'])) {
            $query->where('artist_id', $filters['artist_id']);
        }
        if ($filters['q'] !== '') {
            $query->where(fn ($query) => $query->whereContains('title', $filters['q'])->orWhere('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0));
        }

        return Inertia::render('content/playlists/index', Records::listing($query, $filters,
            fn ($row) => Records::row($row, $row->title ?: 'Playlist #' . $row->id,
                (string) route('staff.playlists.show', ['playlist' => $row->id]), $row->moderation_status, 'artist_id #' . $row->artist_id),
            'staff.playlists.index', self::FILTERS) + ['page' => ['title' => 'Playlists', 'breadcrumbs' => []],
                'description' => 'Content metadata and rights declarations. Private playback requires an authorized review case.']);
    }

    public function show(Request $request, int $playlist): Response
    {
        $id = $playlist;
        $context = Context::from($request);
        $row = DB::table('artist_playlists')->select(self::COLUMNS)->where('id', $id)->firstOrFail();
        $fields = Records::fields($row, self::COLUMNS);
        $owner = DB::table('artists')->where('id', $row->artist_id)->value('user_id');
        $links = [];
        $filters = ListFilters::read($request);
        $items = DB::table('artist_playlist_items')->join('videos', 'videos.id', '=', 'artist_playlist_items.video_id')
            ->where('artist_playlist_items.playlist_id', $id)->select(['videos.id', 'videos.caption'])
            ->orderBy('artist_playlist_items.position')->orderBy('videos.id')->paginate($filters['per_page'], 'page');

        if ($context->can('reports.view')) {
            foreach (DB::table('reports')->where('target_type', 'playlist')->where('target_id', $id)->select(['id', 'status'])->orderBy('id', 'desc')->limit(20)->get() as $case) {
                $links[] = ['label' => 'Case #' . $case->id . ' · ' . $case->status, 'url' => (string) route('staff.reports.show', ['report' => $case->id])];
            }
        }

        return Inertia::render('content/playlists/show', ['page' => ['title' => 'Playlist #' . $id, 'breadcrumbs' => []],
            'fields' => $fields, 'links' => $links,
            'items' => ['rows' => array_map(fn ($video) => ['label' => $video->caption ?: 'Video #' . $video->id,
                'url' => (string) route('staff.videos.show', ['video' => $video->id])], $items->items()),
                'pagination' => $items->paginationMeta(), 'per_page' => $filters['per_page'],
                'url' => (string) route('staff.playlists.show', ['playlist' => $id])],
            'case_target' => $context->can('content.case.create') && (int) $owner !== (int) $context->user->id
                && \App\Models\ArtistPlaylist::visibleTo(null)->whereKey($id)->exists()
                    ? ['type' => 'playlist', 'id' => $id, 'url' => (string) route('staff.cases.create')] : null]);
    }
}
