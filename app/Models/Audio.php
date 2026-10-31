<?php

namespace App\Models;

use App\Services\StorageService;
use Spark\Database\Model;
use Spark\Database\QueryBuilder;
use Spark\Database\Relation\BelongsTo;
use Spark\Database\Relation\HasMany;
use Spark\Facades\DB;

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
        'reuse_allowed',
        'rights_version',
        'rights_confirmed_at',
        'moderation_status',
    ];

    protected array $casts = ['duration' => 'integer', 'reuse_allowed' => 'boolean', 'rights_confirmed_at' => 'datetime'];

    public function remove(): bool
    {
        if (!$this->id) {
            return false;
        }

        return DB::transaction(function (): bool {
            $this->detachOriginal();
            // Preserve the failed selection before ON DELETE SET NULL erases it.
            // Already published clips retain their embedded soundtrack.
            Video::where('audio_id', $this->id)
                ->whereIn('status', [Video::STATUS_PROCESSING, Video::STATUS_FAILED, 'draft', 'scheduled'])
                ->update([
                    'status' => Video::STATUS_SOURCE_UNAVAILABLE, 'processing_state' => 'failed',
                    'publication_state' => 'draft', 'release_error' => 'source_unavailable',
                    'publish_at' => null, 'publish_timezone' => null,
                    'version' => DB::raw('version + 1'), 'updated_at' => now(),
                ]);

            return parent::remove();
        });
    }

    /** Removing the library copy does not remove the soundtrack embedded in its source. */
    public function detachOriginal(): void
    {
        if ($this->origin === 'original' && $this->source_video_id) {
            Video::whereKey($this->source_video_id)->where('user_id', $this->user_id)->where('audio_id', $this->id)
                ->update(['audio_id' => null, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }
    }

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
            ->where('audios.moderation_status', 'approved')
            ->where(function (QueryBuilder $query) use ($viewer): void {
                $query->where('audios.reuse_allowed', true);
                if ($viewer) {
                    $query->orWhere('audios.user_id', $viewer->id);
                }
            })
            ->whereIn('audios.user_id', User::select('users.id')->visibleTo($viewer))
            ->where(function (QueryBuilder $query) use ($viewer): void {
                $query->where('audios.origin', 'upload')
                    ->orWhereIn('audios.source_video_id', Video::select('videos.id')
                        ->published()
                        ->where(function (QueryBuilder $query) use ($viewer): void {
                            $query->where(fn($query) => $query->where('videos.reuse_content', true)->where('videos.visibility', 'public'));
                            if ($viewer) {
                                $query->orWhere('videos.user_id', $viewer->id);
                            }
                        })
                        ->visibleTo($viewer));
            });
    }

    public function scopeWithApiData(QueryBuilder $query, ?User $viewer): QueryBuilder
    {
        $query->select('audios.*')->selectSub(Video::select('publication_state')
            ->whereColumn('videos.id', 'audios.source_video_id')
            ->where('moderation_status', 'approved')
            ->whereNotIn('status', [Video::STATUS_DELETED, Video::STATUS_SOURCE_UNAVAILABLE])
            ->limit(1), 'source_publication_state');
        if ($viewer) {
            $query->withExists(['saves as viewer_saved' => fn(QueryBuilder $query) => $query->where('user_id', $viewer->id)]);
        }

        return $query->with(['user' => fn(QueryBuilder $query) => $query->withApiData($viewer)])
            ->withCount('videos', fn(QueryBuilder $query) => $query->published()->visibleTo($viewer));
    }
}
