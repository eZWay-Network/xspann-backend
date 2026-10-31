<?php

namespace App\Models;

use App\Services\StorageService;
use Spark\Database\{Model, QueryBuilder};
use Spark\Database\Relation\{BelongsTo, BelongsToMany, HasMany};

class Artist extends Model
{
    protected array $fillable = [
        'user_id',
        'stage_name',
        'slug',
        'bio',
        'location',
        'image',
        'featured_video_id',
        'listed',
        'data_since',
    ];

    protected array $casts = [
        'listed' => 'boolean',
        'data_since' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(ArtistGenre::class, 'artist_genre_assignments', 'artist_id', 'genre_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(ArtistLink::class);
    }

    public function playlists(): HasMany
    {
        return $this->hasMany(ArtistPlaylist::class);
    }

    public function featuredVideo(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'featured_video_id');
    }

    public function setImageAttribute(?string $value): ?string
    {
        return StorageService::storedValue($value);
    }

    public function scopeVisibleTo(QueryBuilder $query, ?User $viewer): QueryBuilder
    {
        return $query->where('artists.moderation_status', 'approved')
            ->whereIn('artists.user_id', User::select('users.id')->visibleTo($viewer));
    }

    public function scopeWithApiData(QueryBuilder $query, ?User $viewer): QueryBuilder
    {
        $plan = \Spark\Facades\DB::table('billing_paid_periods as bp')
            ->join('billing_subscriptions as bs', 'bs.id', '=', 'bp.subscription_id')
            ->join('billing_catalog as bc', 'bc.id', '=', 'bs.catalog_id')
            ->select('bc.plan_code')->whereColumn('bs.user_id', 'artists.user_id')
            ->where('bs.environment', config('billing.environment'))->whereNull('bs.deletion_requested_at')
            ->whereNull('bp.revoked_at')->where('bp.starts_at', '<=', \App\Services\Billing\Billing::timestamp())
            ->where('bp.ends_at', '>', \App\Services\Billing\Billing::timestamp())
            ->orderByRaw('bp.ends_at DESC, bp.id DESC')->limit(1);

        return $query->select('artists.*')->selectSub($plan, 'billing_plan')->with([
            'user' => fn($query) => $query->withApiData($viewer),
            'genres' => fn($query) => $query->orderBy('artist_genres.id'),
            'links' => fn($query) => $query->orderBy('position'),
            'featuredVideo' => fn($query) => $query->where('videos.visibility', 'public')
                ->published()->visibleTo($viewer)->withApiData($viewer),
        ]);
    }
}
