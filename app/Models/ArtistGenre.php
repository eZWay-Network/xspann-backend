<?php

namespace App\Models;

use Spark\Database\Model;
use Spark\Facades\DB;

class ArtistGenre extends Model
{
    protected const USE_TIMESTAMPS = false;

    protected array $fillable = ['name', 'slug'];

    /** Existing references remain valid; new assignments must use active genres. */
    public static function assertAssignable(array $ids, array $existing = []): void
    {
        $new = array_values(array_diff(array_map('intval', $ids), array_map('intval', $existing)));
        if (!$new) {
            return;
        }
        $rows = DB::table('artist_genres')->whereIn('id', $new)->lockForUpdate()->get();
        abort_unless(count($rows) === count(array_unique($new)), 422, 'Choose an available genre.');
        foreach ($rows as $row) {
            abort_if((bool) $row->archived, 422, 'Archived genres cannot be newly assigned.');
        }
    }
}
