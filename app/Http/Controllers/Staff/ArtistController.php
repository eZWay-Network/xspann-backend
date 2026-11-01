<?php

namespace App\Http\Controllers\Staff;

use App\Http\Requests\Staff\ListFilters;
use App\Http\Resources\Staff\Records;
use App\Services\Staff\{Context, Sessions};
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class ArtistController
{
    public function index(Request $request): Response
    {
        $filters = ListFilters::read($request, ['listed' => ['yes', 'no']]);
        $query = \App\Models\Artist::select(['id', 'user_id', 'stage_name', 'slug', 'listed', 'moderation_status', 'created_at']);
        if (Context::from($request)->can('billing.view')) {
            $query->withBillingPlan();
        }
        $query->selectSub(\App\Models\Video::published()->visibleTo(null)->selectRaw('COUNT(*)')
            ->whereColumn('videos.user_id', 'artists.user_id'), 'published_count');
        if (!empty($filters['listed'])) {
            $query->where('listed', $filters['listed'] === 'yes');
        }
        if ($filters['q'] !== '') {
            $query->where(fn($query) => $query->whereContains('stage_name', $filters['q'])->orWhere('slug', $filters['q'])->orWhere('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0));
        }

        $data = Records::listing($query, $filters, fn($row) => Records::row(
            $row,
            $row->stage_name,
            (string) route('staff.artists.show', ['artist' => $row->id]),
            $row->moderation_status,
            $row->slug . ' · Owner #' . $row->user_id . ' · ' . ($row->listed ? 'Listed' : 'Unlisted')
            . ' · ' . $row->published_count . ' public videos'
            . (Context::from($request)->can('billing.view') ? ' · Plan: ' . ($row->billing_plan ?? 'free') : '')
        ), 'staff.artists.index', ['listed' => ['yes', 'no']]);
        $genres = DB::table('artist_genre_assignments')->join('artist_genres', 'artist_genres.id', '=', 'artist_genre_assignments.genre_id')
            ->whereIn('artist_id', array_column($data['rows'], 'id'))->select(['artist_id', 'artist_genres.name'])->orderBy('artist_genres.id')->all();
        foreach ($data['rows'] as &$record) {
            $names = array_map(fn($genre) => $genre->name, array_filter($genres, fn($genre) => (int) $genre->artist_id === $record['id']));
            if ($names) {
                $record['description'] .= ' · ' . implode(', ', $names);
            }
        }
        unset($record);

        return Inertia::render('artists/index', $data + [
            'page' => ['title' => 'Artists', 'breadcrumbs' => []],
            'description' => 'Artist profiles, published work and operational references.',
        ]);
    }

    public function show(Request $request, int $artist): Response
    {
        $context = Context::from($request);
        $row = DB::table('artists')->select(['id', 'user_id', 'stage_name', 'slug', 'bio', 'location', 'listed', 'moderation_status', 'created_at'])->where('id', $artist)->firstOrFail();
        $links = [];
        if ($context->can('users.view')) {
            $links[] = ['label' => 'Owner #' . $row->user_id, 'url' => (string) route('staff.users.show', ['user' => $row->user_id])];
        }
        if ($context->can('content.view')) {
            $links[] = ['label' => 'Videos & release metadata', 'url' => (string) route('staff.videos.index')->withQuery(['owner_id' => $row->user_id])];
            $links[] = ['label' => 'Playlists', 'url' => (string) route('staff.playlists.index')->withQuery(['artist_id' => $artist])];
        }
        foreach (['boosts.view' => ['staff.boosts.index', 'Boosts'], 'placements.view' => ['staff.placements.index', 'Placements']] as $ability => [$route, $label]) {
            if ($context->can($ability)) {
                $links[] = ['label' => $label, 'url' => (string) route($route)->withQuery(['artist_id' => $artist])];
            }
        }
        $genres = DB::table('artist_genres')->join('artist_genre_assignments', 'artist_genre_assignments.genre_id', '=', 'artist_genres.id')
            ->where('artist_genre_assignments.artist_id', $artist)->pluck('artist_genres.name');
        $fields = Records::fields($row, ['id', 'stage_name', 'slug', 'bio', 'location', 'moderation_status', 'created_at']);
        $fields[] = ['label' => 'Listing', 'value' => $row->listed ? 'Listed' : 'Unlisted'];
        $fields[] = ['label' => 'Genres', 'value' => implode(', ', $genres)];
        if ($context->can('billing.view')) {
            $fields[] = ['label' => 'Effective plan', 'value' => app(\App\Services\ArtistCapabilities::class)->planFor($row->user_id)];
        }
        $fields[] = ['label' => 'Published videos', 'value' => \App\Models\Video::where('user_id', $row->user_id)->published()->visibleTo(null)->count()];
        if ($context->can('analytics.platform.view')) {
            $snapshot = DB::table('artist_daily_statistics')->where('artist_id', $artist)->orderBy('date', 'desc')->first();
            $fields[] = ['label' => 'Statistics coverage', 'value' => 'Last collected cumulative totals, including approved non-public videos. No historical estimates.'];
            $fields[] = ['label' => 'Last observed', 'value' => Sessions::iso($snapshot?->collected_at) ?? 'No observation available'];
            if ($snapshot) {
                $fields = [...$fields, ...Records::fields($snapshot, \App\Services\ArtistStatistics::METRICS)];
            }
        }
        $publicLinks = DB::table('artist_links')->where('artist_id', $artist)->select(['label', 'url'])->orderBy('position')->limit(20)->all();

        return Inertia::render('artists/show', [
            'page' => ['title' => $row->stage_name, 'breadcrumbs' => []],
            'fields' => $fields,
            'links' => $links,
            'public_links' => array_values(array_filter(array_map(fn($link) =>
                preg_match('~^https?://~i', $link->url) ? ['label' => $link->label, 'url' => $link->url] : null, $publicLinks))),
            'case_target' => $context->can('content.case.create') && (int) $row->user_id !== (int) $context->user->id
                && \App\Models\User::visibleTo(null)->whereKey($row->user_id)->exists()
                ? ['type' => 'user', 'id' => (int) $row->user_id, 'url' => (string) route('staff.cases.create')] : null
        ]);
    }
}
