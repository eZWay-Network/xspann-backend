<?php

namespace App\Models;

use App\Services\StorageService;
use Spark\Database\DB;
use Spark\Database\Model;
use Spark\Database\QueryBuilder;
use Spark\Database\Relation\BelongsTo;
use Spark\Database\Relation\HasMany;

class Video extends Model
{
    public const STATUS_PROCESSING = "processing";
    public const STATUS_PUBLISHED = "published";
    public const STATUS_FAILED = "failed";
    public const STATUS_DELETED = "deleted";

    protected array $fillable = [
        'user_id',
        'audio_id',
        'audio_mode',
        'audio_settings',
        'storage_path',
        'thumbnail_url',
        'caption',
        'sound_name',
        'sound_artist',
        'location_name',
        'visibility',
        'high_quality_upload',
        'scheduled_at',
        'pinned_at',
        'trim_start',
        'trim_end',
        'cut_points',
        'cover_time',
        'crop_mode',
        'text_overlay',
        'original_audio_muted',
        'filter_settings',
        'effect_settings',
        'sound_provider',
        'sound_external_id',
        'sound_preview_url',
        'duration',
        'status',
        'views_count',
        'likes_count',
        'comments_count',
        'saves_count',
        'shares_count',
    ];

    protected array $casts = [
        'audio_settings' => 'array',
        'duration' => 'integer',
        'high_quality_upload' => 'boolean',
        'scheduled_at' => 'datetime',
        'pinned_at' => 'datetime',
        'trim_start' => 'decimal:2',
        'trim_end' => 'decimal:2',
        'cut_points' => 'array',
        'cover_time' => 'decimal:2',
        'original_audio_muted' => 'boolean',
        'filter_settings' => 'array',
        'effect_settings' => 'array',
        'views_count' => 'integer',
        'likes_count' => 'integer',
        'comments_count' => 'integer',
        'saves_count' => 'integer',
        'shares_count' => 'integer',
    ];

    public function setThumbnailUrlAttribute(?string $value): ?string
    {
        return StorageService::storedValue($value);
    }

    public function setSoundPreviewUrlAttribute(?string $value): ?string
    {
        return StorageService::storedValue($value);
    }

    public function setStoragePathAttribute(?string $value): ?string
    {
        return StorageService::storedValue($value);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function audio(): BelongsTo
    {
        return $this->belongsTo(Audio::class);
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

    public function views(): HasMany
    {
        return $this->hasMany(VideoView::class);
    }

    public function scopePublished(QueryBuilder $query): QueryBuilder
    {
        return $query->where('videos.status', self::STATUS_PUBLISHED);
    }

    public function scopeVisibleTo(QueryBuilder $query, ?User $viewer): QueryBuilder
    {
        return $query->whereIn('videos.user_id', User::select('users.id')->visibleTo($viewer))
            ->where(function (QueryBuilder $query) use ($viewer): void {
                $query->where('videos.visibility', 'public');

                if (!$viewer) {
                    return;
                }

                $query->orWhere('videos.user_id', $viewer->id)
                    ->orWhere(function (QueryBuilder $query) use ($viewer): void {
                        $query->where('videos.visibility', 'followers')
                            ->whereIn('videos.user_id', $viewer->following()->select('users.id'));
                    });
            });
    }

    public function scopeWithViewerState(QueryBuilder $query, ?User $viewer): QueryBuilder
    {
        if (!$viewer) {
            return $query;
        }

        return $query
            ->select('videos.*')
            ->withExists([
                'likes as viewer_liked' => fn(QueryBuilder $query) => $query->where('user_id', $viewer->id),
                'saves as viewer_saved' => fn(QueryBuilder $query) => $query->where('user_id', $viewer->id),
            ])
            ->selectSub(
                DB::table('follows')
                    ->selectRaw('1')
                    ->where('follower_id', $viewer->id)
                    ->whereColumn('following_id', 'videos.user_id')
                    ->limit(1),
                'viewer_following',
            );

    }

    public function scopeWithApiData(QueryBuilder $query, ?User $viewer): QueryBuilder
    {
        return $query->withViewerState($viewer)
            ->with([
                'user' => fn($query) => $query->withApiData($viewer),
                'audio' => fn($query) => $query->availableTo($viewer)->withApiData($viewer),
            ]);
    }
}
