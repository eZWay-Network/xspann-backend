<?php

namespace App\Models;

use Spark\Database\Model;
use Spark\Database\Relation\BelongsTo;

class Notification extends Model
{
    public const VIDEO_LIKED = 'video_liked';
    public const VIDEO_COMMENTED = 'video_commented';
    public const COMMENT_REPLIED = 'comment_replied';
    public const COMMENT_REACTED = 'comment_reacted';
    public const USER_FOLLOWED = 'user_followed';
    public const VIDEO_SHARED = 'video_shared';

    protected const UPDATED_AT = null;

    protected array $fillable = ['user_id', 'type', 'data', 'read_at'];

    protected array $casts = [
        'data' => 'array',
        'read_at' => 'datetime'
    ];

    public function save(bool $forceCreate = false): bool
    {
        return \Spark\Facades\DB::transaction(function () use ($forceCreate): bool {
            $saved = parent::save($forceCreate);
            if ($saved && $this->wasCreated()) {
                \App\Services\Realtime::record((int) $this->user_id, 'notification.created', (int) $this->id, 1);
            }

            return $saved;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
