<?php

namespace App\Services;

use App\Jobs\DeleteUnusedMedia;
use App\Models\{Audio, Video};
use Spark\Console\Process;
use Spark\Utils\File;
use Throwable;

class MediaProcessor
{
    public const AUDIO_MIME_TYPES = [
        'audio/mpeg',
        'audio/wav',
        'audio/x-wav',
        'audio/mp4',
        'audio/aac',
        'audio/x-m4a',
        'audio/m4a',
        'audio/ogg',
        'audio/webm',
    ];

    public function video(Video $video): array
    {
        $temporary = [];
        $stored = [];

        try {
            $source = $this->source($video->storage_path, $temporary, ChunkUploads::MAX_BYTES);
            $metadata = $this->probe($source);
            if (!$metadata['video']) {
                throw new \RuntimeException('The upload has no video stream.');
            }

            $output = $this->temporary($temporary);
            $start = (float) ($video->trim_start ?? 0);
            $end = min((float) ($video->trim_end ?? $metadata['duration']), $metadata['duration']);
            $duration = $end - $start;
            if ($start < 0 || $duration < 0.1) {
                throw new \RuntimeException('The selected trim range is outside this video.');
            }

            $settings = $video->audio_settings ?? [];
            $defaultVolume = $video->audio_id && $video->audio_mode === 'mix' && !$video->original_audio_muted && $metadata['audio'] ? 0.5 : 1;
            $originalVolume = max(0, min(1, (float) ($settings['original_volume'] ?? $defaultVolume)));
            $soundVolume = max(0, min(1, (float) ($settings['sound_volume'] ?? $defaultVolume)));
            $command = $this->input($source);
            // Seek the video input before decoding; output time starts at zero after the trim.
            array_splice($command, count($command) - 2, 0, ['-ss', (string) $start]);
            if ($video->audio_id) {
                $audio = Audio::whereKey($video->audio_id)->availableTo($video->user)->first();
                if (!$audio) {
                    throw new \RuntimeException('The selected sound is no longer available.');
                }
                $sound = $this->source($audio->storage_path, $temporary, 52428800);
                $soundDuration = $this->probe($sound)['duration'];
                $soundStart = (float) ($settings['start'] ?? 0);
                if ($soundStart < 0 || $soundStart >= $soundDuration) {
                    throw new \RuntimeException('The selected sound start is outside this sound.');
                }
                array_push($command, '-stream_loop', '-1', '-ss', (string) $soundStart, '-protocol_whitelist', 'file,pipe', '-i', $sound);
                if ($video->audio_mode === 'mix' && !$video->original_audio_muted && $metadata['audio']) {
                    $mix = "[0:a:0]volume={$originalVolume}[original];[1:a:0]volume={$soundVolume}[sound];"
                        . '[original][sound]amix=inputs=2:duration=longest:normalize=0,alimiter=limit=1:level=false:latency=true[a]';
                    array_push($command, '-filter_complex', $mix, '-map', '0:v:0', '-map', '[a]');
                } else {
                    array_push($command, '-map', '0:v:0', '-map', '1:a:0', '-af', "volume={$soundVolume}");
                }
            } else {
                array_push($command, '-map', '0:v:0');
                if (!$video->original_audio_muted) {
                    array_push($command, '-map', '0:a:0?', '-af', "volume={$originalVolume}");
                }
            }

            array_push(
                $command,
                '-t',
                (string) $duration,
                '-vf',
                $this->videoFilters($video),
                '-c:v',
                'libx264',
                '-preset',
                'medium',
                '-crf',
                '23',
                '-maxrate',
                '4M',
                '-bufsize',
                '8M',
                '-profile:v',
                'high',
                '-level:v',
                '3.1',
                '-pix_fmt',
                'yuv420p',
                '-c:a',
                'aac',
                '-b:a',
                '128k',
                '-ar',
                '48000',
                '-ac',
                '2',
                '-map_metadata',
                '-1',
                '-map_chapters',
                '-1',
                '-movflags',
                '+faststart',
                '-threads',
                '2',
                '-f',
                'mp4',
                $output,
            );
            $this->run($command, $output);
            $processed = $this->probe($output, 601);
            if (!$processed['video']) {
                throw new \RuntimeException('Video encoding produced no video stream.');
            }

            $result = [
                'storage_path' => $this->store($video->user_id, $output, 'video.mp4', 'videos', $stored),
                'duration' => (int) ceil($processed['duration']),
            ];
            if (!$video->thumbnail_url) {
                $thumbnail = app(VideoMetadataExtractor::class)->extractLocal($video, $output, $processed['duration']);
                if (isset($thumbnail['thumbnail_url'])) {
                    $result['thumbnail_url'] = $thumbnail['thumbnail_url'];
                    $stored[] = $thumbnail['thumbnail_url'];
                }
            }

            if (!$video->audio_id && !$video->original_audio_muted && $originalVolume > 0 && $processed['audio']) {
                $sound = $this->temporary($temporary);
                $this->encodeAudio($output, $sound, copy: true);
                $result['audio_path'] = $this->store($video->user_id, $sound, 'original.m4a', 'sounds', $stored);
            }

            return $result;
        } catch (Throwable $exception) {
            self::cleanup($video->user_id, $stored);
            throw $exception;
        } finally {
            File::delete($temporary);
        }
    }

    private function videoFilters(Video $video): string
    {
        // Preserve legacy aspect ratios unless the creator explicitly chose a studio crop.
        $geometry = match ($video->crop_mode) {
            'fill' => 'scale=720:1280:force_original_aspect_ratio=increase,crop=720:1280',
            'fit' => 'scale=720:1280:force_original_aspect_ratio=decrease:force_divisible_by=2,pad=720:1280:(ow-iw)/2:(oh-ih)/2:black',
            default => "scale=w='if(gte(iw,ih),min(1280,iw),min(720,iw))':h='if(gte(iw,ih),min(720,ih),min(1280,ih))':force_original_aspect_ratio=decrease:force_divisible_by=2",
        };
        $filters = $video->filter_settings ?? [];
        $brightness = max(-1, min(1, ((float) ($filters['brightness'] ?? 100) - 100) / 100));
        $contrast = max(0, min(2.5, (float) ($filters['contrast'] ?? 100) / 100));
        $saturation = max(0, min(2.5, (float) ($filters['saturation'] ?? 100) / 100));

        return "{$geometry},setsar=1,fps=30,eq=brightness={$brightness}:contrast={$contrast}:saturation={$saturation}";
    }

    public function audio(Audio $audio): array
    {
        $temporary = [];
        $stored = [];

        try {
            $source = $this->source($audio->storage_path, $temporary, 52428800);
            if (!$this->probe($source)['audio']) {
                throw new \RuntimeException('The upload has no audio stream.');
            }
            $output = $this->temporary($temporary);
            $this->encodeAudio($source, $output);
            $metadata = $this->probe($output, 601);
            if (!$metadata['audio']) {
                throw new \RuntimeException('Audio encoding produced no audio stream.');
            }

            return [
                'storage_path' => $this->store($audio->user_id, $output, 'audio.m4a', 'sounds', $stored),
                'duration' => (int) ceil($metadata['duration']),
            ];
        } catch (Throwable $exception) {
            self::cleanup($audio->user_id, $stored);
            throw $exception;
        } finally {
            File::delete($temporary);
        }
    }

    public static function cleanup(int $userId, array $paths): void
    {
        if (!$paths) {
            return;
        }
        try {
            (new DeleteUnusedMedia($userId, $paths))->handle();
        } catch (Throwable) {
            DeleteUnusedMedia::dispatch($userId, $paths);
        }
    }

    private function source(string $value, array &$temporary, int $maxBytes): string
    {
        $location = StorageService::location($value);
        if (!$location || !preg_match('~^(videos|sounds)/[1-9][0-9]*/[a-z0-9_-][a-z0-9._-]*$~Di', $location['key'])) {
            throw new \RuntimeException('Media is outside managed storage.');
        }
        $disk = storage($location['disk']);
        $size = $disk->size($location['key']);
        if ($size < 1 || $size > $maxBytes) {
            throw new \RuntimeException('Media exceeds the supported size range.');
        }
        $source = $location['disk'] === 'public' ? $disk->path($location['key']) : $this->temporary($temporary);
        if ($location['disk'] !== 'public') {
            $disk->downloadFile($location['key'], $source);
        }
        $allowed = str_starts_with($location['key'], 'videos/') ? ChunkUploads::MIME_TYPES : self::AUDIO_MIME_TYPES;
        if (File::size($source) !== $size || !in_array(File::mimeType($source), $allowed, true)) {
            throw new \RuntimeException('Media is incomplete or has an unsupported format.');
        }

        return $source;
    }

    private function probe(string $source, int $maxDuration = 600): array
    {
        $result = Process::run([
            config('app.ffprobe', 'ffprobe'),
            '-v',
            'error',
            '-protocol_whitelist',
            'file,pipe',
            '-show_entries',
            'format=duration:stream=codec_type',
            '-of',
            'json',
            $source,
        ], timeout: 30)->throw();
        $data = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        $duration = (float) ($data['format']['duration'] ?? 0);
        if (!is_finite($duration) || $duration <= 0 || $duration > $maxDuration) {
            throw new \RuntimeException('Media must be between 0 and 600 seconds long.');
        }
        $types = array_column($data['streams'] ?? [], 'codec_type');

        return ['duration' => $duration, 'video' => in_array('video', $types, true), 'audio' => in_array('audio', $types, true)];
    }

    private function input(string $source): array
    {
        return [config('app.ffmpeg', 'ffmpeg'), '-nostdin', '-y', '-v', 'error', '-protocol_whitelist', 'file,pipe', '-i', $source];
    }

    private function encodeAudio(string $source, string $output, bool $copy = false): void
    {
        $codec = $copy
            ? ['-c:a', 'copy']
            : ['-c:a', 'aac', '-b:a', '128k', '-ar', '48000', '-ac', '2'];

        $this->run([
            ...$this->input($source),
            '-map',
            '0:a:0',
            '-vn',
            ...$codec,
            '-map_metadata',
            '-1',
            '-movflags',
            '+faststart',
            '-f',
            'ipod',
            $output,
        ], $output);
    }

    private function run(array $command, string $output): void
    {
        Process::run($command, timeout: 1200)->throw();
        if (!File::isFile($output) || File::size($output) === 0) {
            throw new \RuntimeException('Media encoding produced no output.');
        }
    }

    private function temporary(array &$temporary): string
    {
        File::ensureDirectoryExists($directory = temp_dir('media-processing'));
        $path = tempnam($directory, 'media-');
        if ($path === false) {
            throw new \RuntimeException('Cannot create media staging file.');
        }
        $temporary[] = $path;

        return $path;
    }

    private function store(int $userId, string $source, string $filename, string $kind, array &$stored): string
    {
        $path = StorageService::pathFor($userId, $filename, $kind);
        $value = StorageService::storedValue($path);
        $stored[] = $value;
        StorageService::disk()->putFileAs(dirname($path), $source, basename($path));

        return $value;
    }
}
