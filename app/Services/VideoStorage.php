<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoStorage
{
    public function diskName(): string
    {
        return (string) config('filesystems.video_disk', 'public');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function supportsSignedUploads(): bool
    {
        if ($this->diskName() !== 'spaces') {
            return false;
        }

        $config = config('filesystems.disks.spaces', []);

        foreach (['key', 'secret', 'region', 'bucket', 'endpoint'] as $key) {
            if (empty($config[$key])) {
                return false;
            }
        }

        return true;
    }

    public function pathFor(int $userId, string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'mp4';

        return 'videos/'.$userId.'/'.Str::uuid().'.'.$extension;
    }

    public function audioPathFor(int $userId, string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'mp3';

        return 'sounds/'.$userId.'/'.Str::uuid().'.'.$extension;
    }

    public function thumbnailPathFor(int $userId): string
    {
        return 'thumbnails/'.$userId.'/'.Str::uuid().'.jpg';
    }

    public function storeLocalVideo(int $userId, UploadedFile $file): string
    {
        $path = $this->pathFor($userId, $file->getClientOriginalName());

        $this->disk()->put($path, $file->get());

        return $path;
    }

    public function storeLocalVideoFromPath(int $userId, string $localPath, string $filename): string
    {
        $path = $this->pathFor($userId, $filename);
        $stream = fopen($localPath, 'rb');

        try {
            $this->disk()->put($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $path;
    }

    public function storeLocalAudio(int $userId, UploadedFile $file): string
    {
        $path = $this->audioPathFor($userId, $file->getClientOriginalName());

        $this->disk()->put($path, $file->get());

        return $path;
    }

    public function storeAvatar(int $userId, UploadedFile $file): string
    {
        $extension = $file->extension() ?: $file->getClientOriginalExtension() ?: 'jpg';
        $path = 'avatars/'.$userId.'/'.Str::uuid().'.'.$extension;

        $this->disk()->put($path, $file->get());

        return $path;
    }

    public function storeThumbnail(int $userId, string $localPath): string
    {
        $path = $this->thumbnailPathFor($userId);

        $this->disk()->put($path, file_get_contents($localPath));

        return $path;
    }

    public function localPath(string $path): ?string
    {
        $diskConfig = config('filesystems.disks.'.$this->diskName(), []);

        if (($diskConfig['driver'] ?? null) !== 'local') {
            return null;
        }

        $localPath = rtrim((string) ($diskConfig['root'] ?? ''), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .ltrim($path, DIRECTORY_SEPARATOR);

        return is_file($localPath) ? $localPath : null;
    }

    public function publicUrl(string $path): string
    {
        $diskConfig = config('filesystems.disks.'.$this->diskName(), []);

        if (($diskConfig['driver'] ?? null) === 'local') {
            return $this->applicationUrl().'/media/'.ltrim($path, '/');
        }

        $baseUrl = rtrim((string) ($diskConfig['url'] ?? ''), '/');

        if ($baseUrl !== '') {
            return $baseUrl.'/'.ltrim($path, '/');
        }

        $url = $this->disk()->url($path);

        if (str_starts_with($url, '/')) {
            return rtrim((string) config('app.url'), '/').$url;
        }

        return $url;
    }

    public function normalizeLocalPublicUrl(?string $url): ?string
    {
        if (! $url) {
            return $url;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path) || ! str_starts_with($path, '/storage/')) {
            return $url;
        }

        return $this->publicUrl(substr($path, strlen('/storage/')));
    }

    private function applicationUrl(): string
    {
        if (! app()->runningInConsole()) {
            return request()->getSchemeAndHttpHost();
        }

        return rtrim((string) config('app.url'), '/');
    }
}
