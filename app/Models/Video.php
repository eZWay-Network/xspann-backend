<?php

namespace App\Models;

use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Video extends Model
{
    /** @use HasFactory<VideoFactory> */
    use HasFactory;

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_FAILED = 'failed';

    public const STATUS_DELETED = 'deleted';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'video_url',
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

    protected function casts(): array
    {
        return [
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
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('videos.status', self::STATUS_PUBLISHED);
    }

    public function scopeVisibleTo(Builder $query, ?User $viewer): Builder
    {
        return $query->where(function (Builder $query) use ($viewer): void {
            $query->where('videos.visibility', 'public');

            if (! $viewer) {
                return;
            }

            $query->orWhere('videos.user_id', $viewer->id)
                ->orWhere(function (Builder $query) use ($viewer): void {
                    $query->where('videos.visibility', 'followers')
                        ->whereIn('videos.user_id', $viewer->following()->select('users.id'));
                });
        });
    }

    public function scopeWithViewerState(Builder $query, ?User $viewer): Builder
    {
        if (! $viewer) {
            return $query;
        }

        return $query
            ->withExists([
                'likes as viewer_liked' => fn (Builder $query) => $query->where('user_id', $viewer->id),
                'saves as viewer_saved' => fn (Builder $query) => $query->where('user_id', $viewer->id),
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
}
