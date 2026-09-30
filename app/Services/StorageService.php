<?php

namespace App\Services;

use Spark\Facades\Hash;
use Spark\Foundation\Exceptions\ValidationException;
use Spark\Storage\Storage;
use function in_array;
use function strlen;

class StorageService
{
    public static function diskName(): string
    {
        return config('storage.default', 'public');
    }

    public static function disk(): Storage
    {
        return Storage::disk(self::diskName());
    }

    public static function pathFor(int $userId, string $filename, string $kind = 'videos'): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        $allowed = match ($kind) {
            'sounds' => ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'webm', 'mp4'],
            'avatars' => ['jpg', 'jpeg', 'png', 'webp'],
            'thumbnails' => ['jpg'],
            default => ['mp4', 'mov', 'webm'],
        };

        if (!in_array($extension, $allowed, true)) {
            $extension = $allowed[0];
        }

        return "$kind/$userId/" . Hash::random(16) . ".$extension";
    }

    public static function storeFile(int $userId, array $file, string $kind): string
    {
        // Spark's uploader verifies genuine HTTP uploads and streams local copies.
        $max = match ($kind) {
            'avatars' => 5120, 'sounds' => 51200, default => 512000
        };

        $extensions = match ($kind) {
            'avatars' => ['jpg', 'jpeg', 'png', 'webp'],
            'sounds' => ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'webm', 'mp4'],
            default => ['mp4', 'mov', 'webm'],
        };

        if (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), $extensions, true)) {
            throw ValidationException::withMessages(['file' => ['The uploaded file has an unsupported extension.']]);
        }

        $compress = null;
        $resize = null;

        if ($kind === 'avatars') {
            $resize = [512, 512];
            $compress = 90;
        }

        return self::disk()
            ->uploader("$kind/$userId", extensions: $extensions, maxSize: $max, resize: $resize, compress: $compress)
            ->upload($file);
    }

    public static function storeThumbnail(int $userId, string $localPath): string
    {
        $path = self::pathFor($userId, 'cover.jpg', 'thumbnails');

        return self::disk()->putFileAs(dirname($path), $localPath, basename($path));
    }

    public static function storedValue(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            if ($location = self::location($path)) {
                return $location['disk'] === 'public' ? $location['key'] : storage($location['disk'])->url($location['key']);
            }
            return self::relativePath($path);
        }

        if (config('storage.disks.' . self::diskName() . '.driver') === 's3') {
            return self::disk()->url($path);
        }

        return ltrim($path, '/');
    }

    public static function relativePath(?string $value): ?string
    {
        $prefix = rtrim(media_url(), '/') . '/';

        return $value && str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : $value;
    }

    public static function publicUrl(?string $url): ?string
    {
        if (
            $url && ($location = self::location($url)) && $location['disk'] !== 'public'
            && config('storage.disks.' . $location['disk'] . '.temporary_urls', true)
        ) {
            return storage($location['disk'])->temporaryUrl($location['key'], max(60, min(604800, (int) config('storage.media_url_ttl', 3600))));
        }

        return $url ? media_url($url) : null;
    }

    /** Resolve only configured media locations; never fetch or delete arbitrary URLs. */
    public static function location(?string $value): ?array
    {
        if (!$value) {
            return null;
        }

        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return ['disk' => 'public', 'key' => ltrim($value, '/')];
        }

        $url = strtok($value, '?');
        $public = rtrim(media_url(), '/') . '/';
        if (str_starts_with($url, $public)) {
            return ['disk' => 'public', 'key' => rawurldecode(substr($url, strlen($public)))];
        }

        foreach (config('storage.disks', []) as $name => $config) {
            if (($config['driver'] ?? null) !== 's3' || empty($config['key']) || empty($config['secret']) || empty($config['bucket'])) {
                continue;
            }

            $disk = storage($name);
            $base = dirname($disk->url('__media__')) . '/';

            if (!str_starts_with($url, $base)) {
                $base = dirname(strtok($disk->temporaryUrl('__media__'), '?')) . '/';
            }

            if (str_starts_with($url, $base)) {
                $key = rawurldecode(substr($url, strlen($base)));
                return preg_match('~^(videos|thumbnails|avatars|sounds)/[1-9][0-9]*/[^/]+$~D', $key)
                    ? ['disk' => $name, 'key' => $key] : null;
            }
        }

        return null;
    }

    public static function validateOwner(?string $value, int $userId, string $field, string $kind, bool $required = false): void
    {
        $location = self::location($value);
        if ((!$location && $required) || ($location && !preg_match('~^' . $kind . '/' . $userId . '/[a-z0-9][a-z0-9._-]*$~Di', $location['key']))) {
            throw ValidationException::withMessages([$field => ['Select a file uploaded by this account.']]);
        }
    }

    public static function supportsSignedUploads(): bool
    {
        return config('storage.video_upload_mode') === 'signed'
            && config('storage.disks.' . self::diskName() . '.driver') === 's3';
    }
}
