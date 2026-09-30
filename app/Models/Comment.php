<?php

namespace App\Models;

use Spark\Database\Model;
use Spark\Database\QueryBuilder;
use Spark\Database\Relation\BelongsTo;
use Spark\Database\Relation\HasMany;

class Comment extends Model
{
    protected const UPDATED_AT = null;

    protected array $fillable = ['user_id', 'video_id', 'parent_id', 'body'];

    public function reactions(): HasMany
    {
        return $this->hasMany(CommentReaction::class);
    }

    public function scopeVisibleTo(QueryBuilder $query, ?User $viewer): QueryBuilder
    {
        return $query->whereIn('user_id', User::select('users.id')->visibleTo($viewer));
    }

    public function scopeWithApiData(QueryBuilder $query, ?User $viewer): QueryBuilder
    {
        $query->with(['user' => fn($query) => $query->withApiData($viewer)])
            ->withCount('replies', fn($query) => $query->visibleTo($viewer));

        foreach (CommentReaction::TYPES as $type) {
            $query->withCount("reactions as reactions_{$type}_count", fn($query) => $query->where('reaction_type', $type));
        }

        if ($viewer) {
            $query->selectSub(
                CommentReaction::select('reaction_type')
                    ->where('user_id', $viewer->id)
                    ->whereColumn('comment_id', 'comments.id')
                    ->limit(1),
                'viewer_reaction'
            );
        }

        return $query;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id');
    }
}
