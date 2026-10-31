<?php

namespace App\Models;

use Spark\Database\{Model, QueryBuilder};
use Spark\Database\Relation\{BelongsTo, BelongsToMany};

class ArtistPlaylist extends Model
{
    protected array $fillable = [
        'artist_id',
        'title',
        'description',
        'genre_id',
        'category',
        'visibility',
    ];

    protected array $casts = ['version' => 'integer'];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function videos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class, 'artist_playlist_items', 'playlist_id', 'video_id');
    }

    public function scopeVisibleTo(QueryBuilder $query, ?User $viewer): QueryBuilder
    {
        return $query->where('artist_playlists.visibility', 'public')
            ->where('artist_playlists.moderation_status', 'approved')
            ->whereIn('artist_playlists.artist_id', Artist::select('artists.id')->visibleTo($viewer));
    }

    public function scopeWithApiData(QueryBuilder $query, ?User $viewer): QueryBuilder
    {
        return $query->select('artist_playlists.*')
            ->withCount('videos', fn($query) => $query->published()->visibleTo($viewer))
            ->selectSub(
                Video::select('videos.thumbnail_url')
                    ->join('artist_playlist_items', 'artist_playlist_items.video_id', '=', 'videos.id')
                    ->whereColumn('artist_playlist_items.playlist_id', 'artist_playlists.id')
                    ->published()->visibleTo($viewer)
                    ->orderBy('artist_playlist_items.position')
                    ->limit(1),
                'cover_url',
            );
    }
}
