<?php

namespace App\Models;

use Spark\Database\Model;
use Spark\Database\QueryBuilder;
use Spark\Database\Relation\HasMany;
use Spark\Database\Relation\BelongsToMany;

class User extends Model
{
    protected array $fillable = [
        'name',
        'username',
        'email',
        'password',
        'avatar',
        'bio',
        'status',
        'email_verified_at',
    ];

    protected array $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected array $hidden = ['password', 'remember_token'];

    public function authIdentities(): HasMany
    {
        return $this->hasMany(AuthIdentity::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function scopeVisibleTo(QueryBuilder $query, ?User $viewer): QueryBuilder
    {
        return $query->where('users.status', 'active')
            ->when(
                $viewer,
                fn($query) => $query
                    ->whereNotIn('users.id', Block::select('blocked_id')->where('blocker_id', $viewer->id))
                    ->whereNotIn('users.id', Block::select('blocker_id')->where('blocked_id', $viewer->id))
            );
    }

    public function scopeWithApiData(QueryBuilder $query, ?User $viewer, bool $profile = false): QueryBuilder
    {
        $query->withCount('followers')
            ->withCount('following');

        if ($viewer) {
            $query->withExists('followers as viewer_following', fn($query) => $query->whereKey($viewer->id));
        }

        if ($profile) {
            return $query
                ->withCount('videos', fn($query) => $query->published()->visibleTo($viewer))
                ->withSum('videos as likes_count', 'likes_count', fn($query) => $query->published()->visibleTo($viewer));
        }

        foreach (['thumbnail_url' => 'cover_url', 'storage_path' => 'cover_video_url'] as $column => $alias) {
            $query->selectSub(
                Video::select($column)
                    ->published()->visibleTo($viewer)
                    ->whereColumn('videos.user_id', 'users.id')
                    ->latest()
                    ->orderBy('videos.id', 'DESC')
                    ->limit(1),
                $alias
            );
        }

        return $query;
    }

    public function likes(): HasMany
    {
        return $this->hasMany(Like::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function saves(): HasMany
    {
        return $this->hasMany(Save::class);
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class);
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'following_id', 'follower_id');
    }

    public function following(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'following_id');
    }

    public function likedVideos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class, 'likes');
    }

    public function savedVideos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class, 'saves');
    }

    public function setAvatarAttribute(?string $value): ?string
    {
        return \App\Services\StorageService::storedValue($value);
    }

    public function getNameAttribute(): string
    {
        return $this->attributes['name'] ?? $this->attributes['username'];
    }

    public function hasVerifiedEmail(): bool
    {
        return !empty($this->attributes['email_verified_at']);
    }
}
