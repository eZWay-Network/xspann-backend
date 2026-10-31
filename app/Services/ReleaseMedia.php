<?php

namespace App\Services;

use App\Models\{Audio, Video};
use Spark\Foundation\Exceptions\ValidationException;
use Spark\Http\{Request, Response};
use function is_string;

class ReleaseMedia
{
    public static function diskName(): string
    {
        $name = config('storage.release_disk', 'local');
        self::checkDisk($name);

        return $name;
    }

    private static function checkDisk(string $name): void
    {
        $disk = config('storage.disks.' . $name, []);
        $safe = ($disk['driver'] ?? '') === 'local'
            ? empty($disk['url']) && ($disk['visibility'] ?? '') === 'private'
            : ($disk['driver'] ?? '') === 's3' && empty($disk['acl']) && ($disk['temporary_urls'] ?? true);
        abort_unless($safe, 503, 'Configure a private release disk without public ACLs or local public URLs.');
    }

    public static function validate(?string $reference, string $field, bool $required = false): void
    {
        if (!$reference && !$required) {
            return;
        }
        $location = StorageService::location($reference);
        if (!$location) {
            throw ValidationException::withMessages([$field => ['Draft media must use a managed private upload.']]);
        }
        try {
            self::checkDisk($location['disk']);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => ['Upload draft media with publication_intent=draft before creating this release.']]);
        }
    }

    public static function assertPrivate(Video $video): void
    {
        $references = ['storage_path' => $video->storage_path];
        if ($video->thumbnail_url) {
            $references['thumbnail_url'] = $video->thumbnail_url;
        }
        if ($audio = Audio::where('source_video_id', $video->id)->where('status', '!=', 'deleted')->first()) {
            $references['original_audio'] = $audio->storage_path;
        }
        foreach ($references as $field => $reference) {
            self::validate($reference, $field, true);
            $location = StorageService::location($reference);
            if (!storage($location['disk'])->exists($location['key'])) {
                throw ValidationException::withMessages([$field => ['Release media is missing. Create a new private upload before publication.']]);
            }
        }
    }

    /** The source must never have been issued an immediate/public upload URL. */
    public static function attach(string $reference, int $owner, bool $draft): void
    {
        $location = StorageService::location(StorageService::storedValue($reference));
        $upload = $location ? query('pending_uploads')->where('user_id', $owner)
            ->where('disk', $location['disk'])->where('path', $location['key'])->first() : null;
        if ($draft) {
            self::validate($reference, 'storage_path', true);
            if (!$upload?->private_release) {
                throw ValidationException::withMessages(['storage_path' => ['Use a new upload created with publication_intent=draft.']]);
            }
            $values = StorageService::referenceValues($reference);
            if (Video::whereIn('storage_path', $values)->exists()) {
                throw ValidationException::withMessages(['storage_path' => ['This private upload is already attached to a release.']]);
            }
        } elseif (
            $upload?->private_release || Video::whereIn('storage_path', StorageService::referenceValues($reference))
                ->where('publication_intent', 'draft')->where('publication_state', '!=', 'published')->exists()
        ) {
            throw ValidationException::withMessages(['publication_intent' => ['Create a draft from this private upload, then publish through the release action.']]);
        }
    }

    public static function url(string $reference): string
    {
        $token = rtrim(strtr(base64_encode($reference), '+/', '-_'), '=');
        $expires = time() + 300;
        $signature = hash_hmac('sha256', "media:$token:$expires", config('app.key'));

        return (string) url("api/v1/media/$token?expires=$expires&signature=$signature");
    }

    public static function previewUrl(int $videoId, string $asset): string
    {
        return (string) url("api/v1/me/videos/$videoId/preview/$asset");
    }

    public static function deliver(string $reference, Request $request): Response
    {
        $location = StorageService::location($reference);
        abort_unless($location && preg_match('~^(videos|thumbnails|sounds)/[1-9][0-9]*/[a-zA-Z0-9_.-]+$~D', $location['key']), 404, 'Media not found.');
        $disk = storage($location['disk']);
        if (config('storage.disks.' . $location['disk'] . '.driver') === 's3') {
            self::checkDisk($location['disk']);

            return new Response('', 302, ['Location' => $disk->temporaryUrl($location['key'], 60), 'Cache-Control' => 'private, no-store']);
        }
        $path = $disk->path($location['key']);
        abort_unless(is_file($path) && is_readable($path), 404, 'Media not found.');
        $size = filesize($path);
        $start = 0;
        $end = $size - 1;
        $status = 200;
        $headers = [
            'Content-Type' => match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
                'jpg', 'jpeg' => 'image/jpeg', 'm4a' => 'audio/mp4', 'mp3' => 'audio/mpeg', 'webm' => 'video/webm', default => 'video/mp4',
            },
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Accept-Ranges' => 'bytes',
        ];
        if ($range = $request->header('Range')) {
            if (
                !is_string($range) || !preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $parts)
                || ($parts[1] === '' && $parts[2] === '') || $size === 0
            ) {
                return new Response('', 416, [...$headers, 'Content-Range' => "bytes */$size"]);
            }
            $start = $parts[1] === '' ? max(0, $size - (int) $parts[2]) : (int) $parts[1];
            $end = $parts[1] === '' || $parts[2] === '' ? $size - 1 : min($size - 1, (int) $parts[2]);
            if ($start > $end || $start >= $size) {
                return new Response('', 416, [...$headers, 'Content-Range' => "bytes */$size"]);
            }
            $status = 206;
            $headers['Content-Range'] = "bytes $start-$end/$size";
        }
        $length = max(0, $end - $start + 1);
        $headers['Content-Length'] = (string) $length;
        $stream = fopen($path, 'rb');
        if (!$stream) {
            throw new \RuntimeException('Media cannot be read.');
        }
        // A bounded stream avoids buffering full videos, including Range responses.
        $body = new \App\Support\RangeStream($stream, $start, $length);

        return new Response($body, $status, $headers);
    }
}
