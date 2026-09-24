<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

class VideoMetadataExtractor
{
    /**
     * @var list<string>
     */
    private array $temporarySources = [];

    public function __construct(private readonly VideoStorage $storage) {}

    /**
     * @return array{duration?: int|null, thumbnail_path?: string|null, thumbnail_url?: string|null}
     */
    public function extract(Video $video): array
    {
        try {
            $source = $this->sourcePath($video);

            if (! $source) {
                return [];
            }

            $duration = $this->duration($source);
            $coverTime = $this->coverTime($video, $duration);
            $thumbnail = $this->thumbnail($video, $source, $coverTime);

            return array_filter([
                'duration' => $duration,
                'thumbnail_path' => $thumbnail['path'] ?? null,
                'thumbnail_url' => $thumbnail['url'] ?? null,
            ], fn ($value) => $value !== null);
        } finally {
            File::delete($this->temporarySources);
            $this->temporarySources = [];
        }
    }

    private function sourcePath(Video $video): ?string
    {
        if ($video->storage_path) {
            $localPath = $this->storage->localPath($video->storage_path);

            if ($localPath) {
                return $localPath;
            }

            if ($this->storage->disk()->exists($video->storage_path)) {
                $extension = pathinfo($video->storage_path, PATHINFO_EXTENSION) ?: 'mp4';
                $baseTemporaryPath = tempnam(sys_get_temp_dir(), 'xspann-video-');

                if (! $baseTemporaryPath) {
                    return null;
                }

                $temporaryPath = $baseTemporaryPath.'.'.$extension;
                file_put_contents($temporaryPath, $this->storage->disk()->get($video->storage_path));
                $this->temporarySources[] = $baseTemporaryPath;
                $this->temporarySources[] = $temporaryPath;

                return $temporaryPath;
            }
        }

        return null;
    }

    private function duration(string $source): ?int
    {
        try {
            $process = new Process([
                'ffprobe',
                '-v',
                'error',
                '-show_entries',
                'format=duration',
                '-of',
                'default=noprint_wrappers=1:nokey=1',
                $source,
            ]);
            $process->setTimeout(30);
            $process->run();

            if (! $process->isSuccessful()) {
                return null;
            }

            $duration = (float) trim($process->getOutput());

            return $duration > 0 ? (int) ceil($duration) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{path: string, url: string}|null
     */
    private function thumbnail(Video $video, string $source, float $coverTime): ?array
    {
        $baseThumbnail = tempnam(sys_get_temp_dir(), 'xspann-thumb-');

        if (! $baseThumbnail) {
            return null;
        }

        $thumbnail = $baseThumbnail.'.jpg';

        try {
            $process = new Process([
                'ffmpeg',
                '-y',
                '-ss',
                number_format($coverTime, 2, '.', ''),
                '-i',
                $source,
                '-frames:v',
                '1',
                '-vf',
                'scale=720:-2',
                '-q:v',
                '3',
                $thumbnail,
            ]);
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($thumbnail)) {
                return null;
            }

            $path = $this->storage->storeThumbnail($video->user_id, $thumbnail);

            return [
                'path' => $path,
                'url' => $this->storage->publicUrl($path),
            ];
        } catch (Throwable) {
            return null;
        } finally {
            File::delete([$baseThumbnail, $thumbnail]);
        }
    }

    private function coverTime(Video $video, ?int $duration): float
    {
        if ($video->cover_time !== null) {
            $coverTime = (float) $video->cover_time;

            return $duration ? min(max($coverTime, 0), max($duration - 0.1, 0)) : max($coverTime, 0);
        }

        if ($duration && $duration > 10) {
            return max(1, $duration * 0.1);
        }

        return 1.0;
    }
}
