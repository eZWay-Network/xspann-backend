<?php

namespace App\Http\Controllers\Staff\Settings;

use App\Http\Requests\Staff\{GenreRequest, ListFilters};
use App\Http\Resources\Staff\Records;
use App\Services\Staff\{Context, Taxonomy};
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class TaxonomyController
{
    public function index(Request $request): Response
    {
        $filters = ListFilters::read($request, ['state' => ['active', 'archived']]);
        $query = DB::table('artist_genres')->select(['id', 'name', 'slug', 'archived', 'position', 'version']);
        if ($filters['q']) {
            $query->where('name', 'LIKE', '%' . addcslashes($filters['q'], '%_\\') . '%');
        }
        if ($filters['state']) {
            $query->where('archived', $filters['state'] === 'archived');
        }
        $manage = Context::from($request)->can('taxonomy.manage');

        return Inertia::render('settings/taxonomy', Records::listing($query, $filters, fn ($row) => Records::row($row, $row->name, null, $row->archived ? 'archived' : 'active', $row->slug)
            + ['genre' => ['name' => $row->name, 'slug' => $row->slug, 'position' => (int) $row->position, 'archived' => (bool) $row->archived, 'version' => (int) $row->version],
                'edit_url' => $manage ? (string) route('staff.settings.taxonomy.update', ['genre' => $row->id]) : null],
            'staff.settings.taxonomy', ['state' => ['active', 'archived']]) + [
                'page' => ['title' => 'Taxonomy', 'breadcrumbs' => []],
                'description' => 'Archived genres remain on existing records and are excluded from new selections. Categories are deployment-controlled.',
                'store_url' => $manage ? (string) route('staff.settings.taxonomy.store') : null,
                'categories' => config('artists.playlist_categories'),
            ]);
    }

    public function store(GenreRequest $request): Response
    {
        return $this->save($request, null);
    }

    public function update(GenreRequest $request, int $genre): Response
    {
        return $this->save($request, $genre);
    }

    private function save(GenreRequest $request, ?int $genre): Response
    {
        Taxonomy::save(Context::from($request), $genre, $request->validated()->toArray());
        Inertia::flash('success', 'Genre saved.');

        return Inertia::redirect((string) route('staff.settings.taxonomy'), 303);
    }
}
