<?php

namespace App\Models;

use Spark\Database\Model;

class CommentReaction extends Model
{
    protected const UPDATED_AT = null;

    public const TYPES = ['like', 'love', 'haha', 'wow', 'sad', 'angry'];

    protected string $table = 'comments_reacts';

    protected array $fillable = ['comment_id', 'user_id', 'reaction_type'];
}
