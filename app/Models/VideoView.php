<?php

namespace App\Models;

use Spark\Database\Model;
use Spark\Database\Relation\BelongsTo;

class VideoView extends Model
{
    protected const UPDATED_AT = null;

    protected array $fillable = [
        'user_id',
        'video_id',
        'ip_hash',
        'user_agent_hash',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
