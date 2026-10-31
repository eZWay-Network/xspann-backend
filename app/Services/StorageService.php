<?php

namespace App\Services;

use Spark\Facades\Hash;
use Spark\Foundation\Exceptions\ValidationException;
use Spark\Storage\{Storage, StoragePath};
use InvalidArgumentException;
use function array_key_exists;
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
            'avatars', 'artist-images' => ['jpg', 'jpeg', 'png', 'webp'],
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
            'avatars', 'artist-images' => 5120, 'sounds' => 51200, default => 512000
        };

        $extensions = match ($kind) {
            'avatars', 'artist-images' => ['jpg', 'jpeg', 'png', 'webp'],
            'sounds' => ['mp3', 'wav', 'm4a', 'aac', 'ogg', 'webm', 'mp4'],
            default => ['mp4', 'mov', 'webm'],
        };

        if (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), $extensions, true)) {
            throw ValidationException::withMessages(['file' => ['The uploaded file has an unsupported extension.']]);
        }

        $compress = null;
        $resize = null;

        if (in_array($kind, ['avatars', 'artist-images'], true)) {
            $resize = [512, 512];
            $compress = 90;
        }

        $path = self::disk()
            ->uploader("$kind/$userId", extensions: $extensions, maxSize: $max, resize: $resize, compress: $compress)
            ->upload($file);

        PendingUploads::track($userId, $path);

        return $path;
    }

    public static function storeThumbnail(int $userId, string $localPath): string
    {
        $path = self::pathFor($userId, 'cover.jpg', 'thumbnails');

        return self::disk()->putFileAs(dirname($path), $localPath, basename($path));
    }

    /** Normalize a new write; bare upload keys belong to the current upload disk. */
    public static function storedValue(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $location = self::location($value);

            return $location ? $location['disk'] . ':' . $location['key'] : $value;
        }

        $reference = str_contains($value, ':') ? $value : self::diskName() . ':' . ltrim($value, '/');
        $location = self::location($reference);

        if (!$location) {
            throw new InvalidArgumentException('Invalid media disk or key.');
        }

        return $location['disk'] . ':' . $location['key'];
    }

    public static function publicUrl(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $location = self::location($value);
        if (!$location) {
            return filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
        }

        $config = config('storage.disks.' . $location['disk'], []);
        $disk = storage($location['disk']);

        if (($config['driver'] ?? null) === 's3' && ($config['temporary_urls'] ?? true)) {
            return $disk->temporaryUrl($location['key'], max(60, min(604800, (int) config('storage.media_url_ttl', 3600))));
        }

        // A private local disk has no public route; never invent an uploads URL for it.
        if (($config['driver'] ?? null) === 'local' && empty($config['url'])) {
            return null;
        }

        return $disk->url($location['key']);
    }

    /** Read explicit references and legacy values without depending on the default disk. */
    public static function location(?string $value): ?array
    {
        if (!$value) {
            return null;
        }

        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            [$disk, $key] = str_contains($value, ':')
                ? explode(':', $value, 2)
                : ['public', ltrim($value, '/')];

            return self::normalizeLocation($disk, $key);
        }

        $url = strtok($value, '?');
        foreach (config('storage.disks', []) as $name => $config) {
            $bases = [];
            if (!empty($config['url'])) {
                $bases[] = rtrim($config['url'], '/') . '/';
            }
            if ($name === 'public') {
                $bases[] = rtrim(media_url(), '/') . '/';
            }
            if (($config['driver'] ?? null) === 's3' && !empty($config['key']) && !empty($config['secret']) && !empty($config['bucket'])) {
                $disk = storage($name);
                $bases[] = dirname($disk->url('__media__')) . '/';
                $bases[] = dirname(strtok($disk->temporaryUrl('__media__'), '?')) . '/';
            }

            foreach (array_unique($bases) as $base) {
                if (str_starts_with($url, $base)) {
                    $key = rawurldecode(substr($url, strlen($base)));
                    if (!preg_match('~^(videos|thumbnails|avatars|artist-images|sounds)/[1-9][0-9]*/[^/]+$~D', $key)) {
                        return null;
                    }

                    return self::normalizeLocation($name, $key);
                }
            }
        }

        return null;
    }

    /** Equivalent stored values, so cleanup preserves both new and legacy references. */
    public static function referenceValues(string $value): array
    {
        $location = self::location($value);
        if (!$location) {
            return [$value];
        }

        ['disk' => $disk, 'key' => $key] = $location;
        $config = config("storage.disks.$disk", []);
        $values = [$value, "$disk:$key"];

        if ($disk === 'public') {
            $values[] = $key;
            $values[] = media_url($key);
        }
        if (($config['driver'] ?? null) === 's3' || !empty($config['url'])) {
            $values[] = storage($disk)->url($key);
        }

        return array_values(array_unique($values));
    }

    private static function normalizeLocation(string $disk, string $key): ?array
    {
        if (!array_key_exists($disk, config('storage.disks', []))) {
            return null;
        }

        try {
            return ['disk' => $disk, 'key' => StoragePath::normalize($key)];
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function validateOwner(?string $value, int $userId, string $field, string $kind, bool $required = false): void
    {
        try {
            $location = self::location(self::storedValue($value));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([$field => ['Select a file uploaded by this account.']]);
        }

        if ((!$location && $required) || ($location && !preg_match('~^' . $kind . '/' . $userId . '/[a-z0-9_-][a-z0-9._-]*$~Di', $location['key']))) {
            throw ValidationException::withMessages([$field => ['Select a file uploaded by this account.']]);
        }
        if ($location && !storage($location['disk'])->exists($location['key'])) {
            throw ValidationException::withMessages([$field => ['The upload is missing or has expired. Please upload it again.']]);
        }
    }

    public static function supportsSignedUploads(): bool
    {
        return config('storage.video_upload_mode') === 'signed'
            && config('storage.disks.' . self::diskName() . '.driver') === 's3';
    }
}
