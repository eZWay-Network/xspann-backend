<?php

namespace App\Services;

use App\Models\{Audio, User, Video};
use Spark\Facades\{DB, Lock};

class PendingUploads
{
    public static function track(int $userId, string $path): void
    {
        try {
            DB::table('pending_uploads')->insert([
                'user_id' => $userId,
                'disk' => StorageService::diskName(),
                'path' => $path,
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            // Do not leave an untracked completed upload if persistence fails.
            StorageService::disk()->delete($path);
            throw $exception;
        }
    }

    public static function locked(int $userId, callable $callback): mixed
    {
        return Lock::withLock("media.attach.$userId", $callback, timeout: 1800, waitTimeout: 10);
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
                    if (!preg_match("~^(videos|avatars|thumbnails|sounds)/{$upload->user_id}/[a-z0-9_-][a-z0-9._-]*$~Di", $path)) {
                        throw new \RuntimeException('Invalid pending upload path.');
                    }

                    $disk = storage($upload->disk);
                    $values = [$disk->url($path)];
                    if (config('storage.disks.' . $upload->disk . '.driver') === 'local') {
                        $values[] = $path;
                        $values[] = media_url($path);
                    }

                    $referenced = User::whereIn('avatar', $values)->exists()
                        || Audio::whereIn('storage_path', $values)->exists()
                        || Video::whereIn('storage_path', $values)
                            ->orWhereIn('thumbnail_url', $values)
                            ->orWhereIn('sound_preview_url', $values)
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
