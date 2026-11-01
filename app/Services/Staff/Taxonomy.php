<?php

namespace App\Services\Staff;

use App\Services\AuditTrail;
use Spark\Facades\DB;

final class Taxonomy
{
    public static function save(Context $context, ?int $id, array $input): void
    {
        Team::locked(function () use ($context, $id, $input): void {
            Sessions::requireCurrent($context)->authorize('taxonomy.manage');
            $row = $id ? DB::table('artist_genres')->where('id', $id)->lockForUpdate()->firstOrFail() : null;
            abort_unless((int) ($row?->version ?? 0) === (int) $input['version'], 409, 'This genre changed. Reload before saving.');
            $name = trim(preg_replace('/\s+/u', ' ', $input['name']));
            abort_unless(mb_strlen($name) >= 2, 422, 'Enter a genre name.');
            $duplicate = DB::table('artist_genres')->where('id', '!=', $id ?? 0)
                ->where('slug', $input['slug'])->exists();
            abort_if($duplicate, 422, 'A genre with that name or slug already exists.');
            // SQL LOWER is ASCII-only on SQLite. Compare Unicode labels consistently across drivers.
            foreach (DB::table('artist_genres')->where('id', '!=', $id ?? 0)->pluck('name') as $existing) {
                $normalized = trim(preg_replace('/\s+/u', ' ', $existing));
                abort_if(mb_strtolower($normalized) === mb_strtolower($name), 422, 'A genre with that name or slug already exists.');
            }
            $values = ['name' => $name, 'slug' => $input['slug'], 'position' => (int) $input['position'],
                'archived' => (bool) $input['archived'], 'version' => (int) $input['version'] + 1];
            if ($id) {
                DB::table('artist_genres')->where('id', $id)->update($values);
            } else {
                $id = (int) DB::table('artist_genres')->insert($values);
            }
            AuditTrail::record('staff.taxonomy.saved', ['genre_id' => $id, 'name' => $name, 'archived' => (bool) $input['archived'], 'version' => $values['version']], $context->user->id, 'staff');
        });
    }
}
