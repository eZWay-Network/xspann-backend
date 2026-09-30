<?php

namespace App\Models;

use Spark\Database\Model;
use Spark\Database\Relation\BelongsTo;

class Notification extends Model
{
    protected const UPDATED_AT = null;

    protected array $fillable = ['user_id', 'type', 'data', 'read_at'];

    protected array $casts = [
        'data' => 'array',
        'read_at' => 'datetime'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
