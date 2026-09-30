<?php

namespace App\Models;

use Spark\Database\Model;
use Spark\Database\Relation\BelongsTo;

class Like extends Model
{
    protected const UPDATED_AT = null;

    protected array $fillable = ['user_id', 'video_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
