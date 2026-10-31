<?php

namespace App\Models;

use Spark\Database\Model;

class ArtistGenre extends Model
{
    protected const USE_TIMESTAMPS = false;

    protected array $fillable = ['name', 'slug'];
}
