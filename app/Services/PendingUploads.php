<?php

namespace App\Services;

use App\Models\{Artist, Audio, User, Video};
use Spark\Facades\{DB, Cache};

class PendingUploads
{
    public static function track(int $userId, string $path, ?string $diskName = null, bool $privateRelease = false): void
    {
        $diskName ??= StorageService::diskName();
        try {
            DB::table('pending_uploads')->upsert([
                'user_id' => $userId,
                'disk' => $diskName,
                'path' => $path,
                'private_release' => $privateRelease,
                'created_at' => now(),
            ], ['disk', 'path'], ['created_at']);
        } catch (\Throwable $exception) {
            // Do not leave an untracked completed upload if persistence fails.
            storage($diskName)->delete($path);
            throw $exception;
        }
    }

    public static function locked(int $userId, callable $callback): mixed
    {
        return Cache::lock("media.attach.$userId", 1800)->block(10, $callback);
    }

    public static function attach(int $userId, callable $callback): mixed
    {
        return self::locked($userId, fn() => DB::transaction(function () use ($userId, $callback): mixed {
            // Serialize attachment with deletion's account-state transition.
            User::increment('id', 0, where: ['id' => $userId]);

            abort_unless(
                User::visibleTo(null)->whereKey($userId)->exists(),
                403,
                'This account cannot attach media.'
            );

            return $callback();
        }));
    }

    public static function prune(int $cutoff): void
    {
        $lastId = 0;
        do {
            $uploads = DB::table('pending_uploads')
                ->where('created_at', '<', date('Y-m-d H:i:s', $cutoff))
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(100)
                ->get();

            foreach ($uploads as $upload) {
                $lastId = $upload->id;
                self::locked($upload->user_id, function () use ($upload): void {
                    $path = $upload->path;
                    if (!preg_match("~^(videos|avatars|artist-images|thumbnails|sounds)/{$upload->user_id}/[a-z0-9_-][a-z0-9._-]*$~Di", $path)) {
                        throw new \RuntimeException('Invalid pending upload path.');
                    }

                    $disk = storage($upload->disk);
                    $values = StorageService::referenceValues($upload->disk . ":$path");

                    $referenced = Artist::whereIn('image', $values)->exists()
                        || User::whereIn('avatar', $values)->exists()
                        || Audio::whereIn('storage_path', $values)->exists()
                        || Video::whereIn('storage_path', $values)
                            ->orWhereIn('thumbnail_url', $values)
                            ->exists();

                    if (!$referenced && !$disk->delete($path)) {
                        throw new \RuntimeException('Cannot remove abandoned upload.');
                    }

                    DB::table('pending_uploads')->where('id', $upload->id)->delete();
                });
            }
        } while (\count($uploads) === 100);
    }
}
