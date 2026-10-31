<?php

namespace App\Models;

use Spark\Database\Model;
use Spark\Database\Relation\BelongsTo;

class Report extends Model
{
    protected array $fillable = [
        'user_id',
        'subject_user_id',
        'video_id',
        'reported_user_id',
        'audio_id',
        'comment_id',
        'playlist_id',
        'target_type',
        'target_id',
        'reason',
        'details',
        'evidence',
        'status',
        'version',
    ];

    protected array $casts = ['evidence' => 'encrypted', 'version' => 'integer'];

    protected array $hidden = ['evidence'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function reportedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }
}
