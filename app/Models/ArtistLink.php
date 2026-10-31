<?php

namespace App\Models;

use Spark\Database\Model;

class ArtistLink extends Model
{
    protected const USE_TIMESTAMPS = false;

    protected array $fillable = [
        'artist_id',
        'label',
        'type',
        'url',
        'position',
    ];

    protected array $casts = ['position' => 'integer'];
}
