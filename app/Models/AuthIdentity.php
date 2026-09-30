<?php

namespace App\Models;

use Spark\Database\Model;
use Spark\Database\Relation\BelongsTo;

class AuthIdentity extends Model
{
    protected const UPDATED_AT = null;

    protected array $fillable = ['user_id', 'provider', 'provider_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
