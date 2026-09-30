<?php

namespace App\Services;

use App\Models\Video;
use Spark\Console\Process;
use Spark\Facades\Storage;
use Spark\Utils\File;
use Throwable;

class VideoMetadataExtractor
{
    /**
     * @return array{duration?: int|null, thumbnail_path?: string|null, thumbnail_url?: string|null}
     */
    public function extract(Video $video): array
    {
        $temporary = [];

        try {
            if (!$video->storage_path) {
                return [];
            }

            $location = StorageService::location($video->storage_path);
            if (!$location) {
                return [];
            }

            $disk = Storage::disk($location['disk']);
            $key = $location['key'];

            $source = $location['disk'] === 'public' ? $disk->path($key) : null;
            if ($source !== null && !File::isFile($source)) {
                throw new \RuntimeException('Uploaded video was not found.');
            }

            if ($source === null) {
                $size = $disk->size($key);
                if ($size < 1 || $size > ChunkUploads::MAX_BYTES) {
                    throw new \RuntimeException('Uploaded video exceeds the supported size range.');
                }

                $source = $this->temporary('source');
                $temporary[] = $source;
                $disk->downloadFile($key, $source);

                if (File::size($source) !== $size || !\in_array(File::mimeType($source), ChunkUploads::MIME_TYPES, true)) {
                    throw new \RuntimeException('Uploaded video is incomplete or has an unsupported format.');
                }
            }

            $duration = $this->duration($source);
            $coverTime = $this->coverTime($video, $duration);
            $thumbnail = $this->thumbnail($video, $source, $coverTime);

            return array_filter(['duration' => $duration !== null ? (int) ceil($duration) : null, ...$thumbnail], fn($value) => $value !== null);
        } finally {
            File::delete($temporary);
        }
    }

    private function duration(string $source): ?float
    {
        try {
            $probe = Process::run([
                config('app.ffprobe', 'ffprobe'),
                '-v',
                'error',
                '-protocol_whitelist',
                'file,pipe',
                '-show_entries',
                'format=duration',
                '-of',
                'default=noprint_wrappers=1:nokey=1',
                $source,
            ], timeout: 30)->throw();

            $seconds = (float) trim($probe->output());
            return is_finite($seconds) && $seconds > 0 ? $seconds : null;
        } catch (Throwable) {
            // Metadata is optional; decoder errors do not prevent publication.
            return null;
        }
    }

    /**
     * @return array{thumbnail_path?: string, thumbnail_url?: string}
     */
    private function thumbnail(Video $video, string $source, float $coverTime): array
    {
        $thumbnail = null;

        try {
            $thumbnail = $this->temporary('thumbnail');

            Process::run([
                config('app.ffmpeg', 'ffmpeg'),
                '-nostdin',
                '-y',
                '-ss',
                number_format($coverTime, 2, '.', ''),
                '-protocol_whitelist',
                'file,pipe',
                '-i',
                $source,
                '-frames:v',
                '1',
                '-vf',
                'scale=720:-2',
                '-q:v',
                '3',
                '-f',
                'image2',
                '-c:v',
                'mjpeg',
                $thumbnail,
            ], timeout: 60)->throw();

            if (!File::isFile($thumbnail) || File::size($thumbnail) === 0) {
                return [];
            }

            $path = StorageService::storeThumbnail((int) $video->user_id, $thumbnail);

            return [
                'thumbnail_path' => $path,
                'thumbnail_url' => StorageService::storedValue($path)
            ];
        } catch (Throwable) {
            return [];
        } finally {
            $thumbnail && File::delete($thumbnail);
        }
    }

    private function temporary(string $prefix): string
    {
        File::ensureDirectoryExists($directory = storage_dir('temp/video-processing'));

        $path = tempnam($directory, $prefix);
        if ($path === false) {
            throw new \RuntimeException('Cannot create video staging file.');
        }

        return $path;
    }

    private function coverTime(Video $video, ?float $duration): float
    {
        $coverTime = $video->cover_time !== null
            ? (float) $video->cover_time
            : ($duration !== null && $duration > 10 ? $duration * 0.1 : 1.0);

        if ($duration !== null) {
            return min(max($coverTime, 0), max($duration - 0.1, 0));
        }

        return max($coverTime, 0);
    }
}
