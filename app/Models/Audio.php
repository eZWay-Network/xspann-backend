<?php

namespace App\Models;

use App\Services\StorageService;
use Spark\Database\Model;
use Spark\Database\QueryBuilder;
use Spark\Database\Relation\BelongsTo;
use Spark\Database\Relation\HasMany;

class Audio extends Model
{
    protected string $table = 'audios';

    protected array $fillable = [
        'user_id',
        'source_video_id',
        'title',
        'storage_path',
        'origin',
        'status',
        'duration',
    ];

    protected array $casts = ['duration' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function saves(): HasMany
    {
        return $this->hasMany(AudioSave::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function setStoragePathAttribute(?string $value): ?string
    {
        return StorageService::storedValue($value);
    }

    public function scopeAvailableTo(QueryBuilder $query, ?User $viewer): QueryBuilder
    {
        return $query->where('audios.status', 'ready')
            ->whereIn('audios.user_id', User::select('users.id')->visibleTo($viewer))
            ->where(function (QueryBuilder $query) use ($viewer): void {
                $query->where('audios.origin', 'upload')
                    ->orWhereIn('audios.source_video_id', Video::select('videos.id')
                        ->published()
                        ->where('videos.reuse_content', true)
                        ->where('videos.visibility', 'public')
                        ->visibleTo($viewer));
            });
    }

    public function scopeWithApiData(QueryBuilder $query, ?User $viewer): QueryBuilder
    {
        if ($viewer) {
            $query->withExists(['saves as viewer_saved' => fn(QueryBuilder $query) => $query->where('user_id', $viewer->id)]);
        }

        return $query->with(['user' => fn(QueryBuilder $query) => $query->withApiData($viewer)])
            ->withCount('videos', fn(QueryBuilder $query) => $query->published()->visibleTo($viewer));
    }
}
