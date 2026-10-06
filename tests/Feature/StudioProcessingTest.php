<?php

namespace Tests\Feature;

use App\Models\Audio;
use App\Services\MediaProcessor;
use Spark\Console\Process;
use Spark\Utils\File;
use Tests\TestCase;

class StudioProcessingTest extends TestCase
{
    private function tools(): array
    {
        $binaries = [];
        foreach (['ffmpeg', 'ffprobe'] as $binary) {
            foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
                if (is_executable("$directory/$binary")) {
                    $binaries[$binary] = "$directory/$binary";
                    break;
                }
            }
        }
        if (count($binaries) !== 2) {
            $this->markTestSkipped('FFmpeg and FFprobe are required.');
        }
        $this->app->mergeConfig(['app' => $binaries]);

        return $binaries;
    }

    public function testStudioSettingsAreValidatedStoredDocumentedAndImmutable(): void
    {
        $user = $this->makeUser();
        storage('public')->put('videos/1/upload.mp4', 'upload');
        $settings = ['start' => 0.5, 'original_volume' => 0.2, 'sound_volume' => 0.8];
        $id = $this->asUser($user)->postJson('/api/v1/videos', [
            'storage_path' => 'videos/1/upload.mp4',
            'audio_settings' => $settings,
        ])->assertStatus(201)->assertJsonPath('data.audio_settings', $settings)->json('data.id');
        $this->patchJson("/api/v1/videos/$id", ['audio_settings' => $settings])->assertStatus(422);
        foreach ([['start' => -1], ['start' => 601], ['original_volume' => 1.1], ['sound_volume' => -0.1]] as $invalid) {
            $this->postJson('/api/v1/videos', [
                'storage_path' => 'videos/1/upload.mp4',
                'audio_settings' => $invalid,
            ])->assertStatus(422);
        }
        $this->get('/')->assertOk()->assertSee('audio_settings.original_volume')->assertSee('Library audio loops');
    }

    public function testRenderedAudioFlagIsValidatedAndCannotBeChanged(): void
    {
        $user = $this->makeUser();
        storage('public')->put('videos/1/rendered.mp4', 'upload');
        $id = $this->asUser($user)->postJson('/api/v1/videos', [
            'storage_path' => 'videos/1/rendered.mp4',
            'audio_settings' => ['rendered' => true],
        ])->assertStatus(201)->assertJsonPath('data.audio_settings.rendered', true)->json('data.id');

        $this->patchJson("/api/v1/videos/$id", [
            'audio_settings' => ['rendered' => false],
        ])->assertStatus(422);
        $this->postJson('/api/v1/videos', [
            'storage_path' => 'videos/1/rendered.mp4',
            'audio_settings' => ['rendered' => 'invalid'],
        ])->assertStatus(422);
        $this->postJson('/api/v1/videos', [
            'storage_path' => 'videos/1/rendered.mp4',
            'audio_id' => 999,
            'audio_settings' => ['rendered' => true],
        ])->assertStatus(422);
        $this->get('/')->assertOk()->assertSee('audio_settings.rendered=true');
    }

    public function testRenderedMixKeepsUploadedAudioAndLibraryAttribution(): void
    {
        $tools = $this->tools();
        $user = $this->makeUser();
        // The library file is deliberately absent: a rendered mix must not decode it again.
        $track = Audio::create([
            'user_id' => $user->id,
            'title' => 'Already mixed',
            'storage_path' => 'sounds/1/not-needed.m4a',
            'status' => 'ready',
            'duration' => 3,
        ]);
        $video = $this->makeVideo($user, [
            'audio_id' => $track->id,
            'audio_mode' => 'replace',
            'audio_settings' => ['rendered' => true, 'original_volume' => 0, 'sound_volume' => 0],
        ]);
        $source = storage('public')->path($video->storage_path);
        File::ensureDirectoryExists(dirname($source));
        Process::run([
            $tools['ffmpeg'],
            '-v',
            'error',
            '-f',
            'lavfi',
            '-i',
            'color=red:s=90x160:d=2',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=220:duration=2:sample_rate=48000',
            '-c:v',
            'libx264',
            '-c:a',
            'aac',
            $source,
        ])->throw();

        $result = app(MediaProcessor::class)->video($video);
        $this->assertSame($track->id, $video->audio_id);
        $this->assertTrue(!isset($result['audio_path']));
        $pcmPath = $this->storagePath . '/rendered.pcm';
        Process::run([
            $tools['ffmpeg'],
            '-v',
            'error',
            '-i',
            storage('public')->path($result['storage_path']),
            '-t',
            '0.5',
            '-vn',
            '-ac',
            '1',
            '-ar',
            '48000',
            '-f',
            's16le',
            $pcmPath,
        ])->throw();
        $samples = array_map(
            fn($n) => $n > 32767 ? $n - 65536 : $n,
            array_values(unpack('v*', file_get_contents($pcmPath))),
        );
        $rms = sqrt(array_sum(array_map(fn($n) => $n * $n, $samples)) / count($samples));
        $this->assertTrue($rms > 1800 && $rms < 3300);

        // String booleans are accepted by validation; "false" must still select server mixing.
        $video->audio_settings = ['rendered' => 'false'];
        $this->assertThrows(\RuntimeException::class, fn() => app(MediaProcessor::class)->video($video));
        $video->audio_settings = ['rendered' => true];

        // Attribution still obeys availability checks even when the sound is baked into the file.
        $track->update(['status' => 'failed']);
        $this->assertThrows(\RuntimeException::class, fn() => app(MediaProcessor::class)->video($video));
    }

    public function testRealTrimPortraitCropAndMonochromeChangeTheEncodedFrames(): void
    {
        $tools = $this->tools();
        $user = $this->makeUser();
        $video = $this->makeVideo($user, [
            'trim_start' => 1,
            'trim_end' => 2,
            'crop_mode' => 'fill',
            'filter_settings' => ['saturation' => 0, 'contrast' => 100, 'brightness' => 100],
        ]);
        $source = storage('public')->path($video->storage_path);
        File::ensureDirectoryExists(dirname($source));
        Process::run([
            $tools['ffmpeg'],
            '-v',
            'error',
            '-f',
            'lavfi',
            '-i',
            'color=red:s=160x90:d=1',
            '-f',
            'lavfi',
            '-i',
            'color=blue:s=160x90:d=2',
            '-filter_complex',
            '[0:v][1:v]concat=n=2:v=1:a=0',
            '-c:v',
            'libx264',
            $source,
        ])->throw();
        $result = app(MediaProcessor::class)->video($video);
        $output = storage('public')->path($result['storage_path']);
        $probe = json_decode(Process::run([
            $tools['ffprobe'],
            '-v',
            'error',
            '-show_streams',
            '-show_format',
            '-of',
            'json',
            $output,
        ])->throw()->output(), true);
        $this->assertSame(720, $probe['streams'][0]['width']);
        $this->assertSame(1280, $probe['streams'][0]['height']);
        $this->assertTrue(abs((float) $probe['format']['duration'] - 1) < 0.1);
        $pixelPath = $this->storagePath . '/pixel.rgb';
        Process::run([
            $tools['ffmpeg'],
            '-v',
            'error',
            '-i',
            $output,
            '-frames:v',
            '1',
            '-vf',
            'scale=1:1',
            '-pix_fmt',
            'rgb24',
            '-f',
            'rawvideo',
            $pixelPath,
        ])->throw();
        $pixel = file_get_contents($pixelPath);
        $this->assertTrue(abs(ord($pixel[0]) - ord($pixel[1])) <= 4);
        $this->assertTrue(abs(ord($pixel[1]) - ord($pixel[2])) <= 4);
        // The blue segment is much darker than the red first second: trimming chose the right segment.
        $this->assertTrue(ord($pixel[0]) < 50);
        $this->assertSame([], glob($this->storagePath . '/temp/media-processing/*'));
    }

    public function testSoundOffsetLoopAndVolumeAreAppliedToPublishedAudio(): void
    {
        $tools = $this->tools();
        $user = $this->makeUser();
        $track = Audio::create([
            'user_id' => $user->id,
            'title' => 'Offset fixture',
            'storage_path' => 'sounds/1/fixture.wav',
            'status' => 'ready',
            'duration' => 2,
        ]);
        $sound = storage('public')->path($track->storage_path);
        File::ensureDirectoryExists(dirname($sound));
        Process::run([
            $tools['ffmpeg'],
            '-v',
            'error',
            '-f',
            'lavfi',
            '-i',
            'anullsrc=r=48000:cl=mono:d=1',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=880:duration=1:sample_rate=48000',
            '-filter_complex',
            '[0:a][1:a]concat=n=2:v=0:a=1',
            $sound,
        ])->throw();
        $video = $this->makeVideo($user, [
            'audio_id' => $track->id,
            'audio_mode' => 'mix',
            'audio_settings' => ['start' => 1, 'original_volume' => 0, 'sound_volume' => 0.5],
            'trim_start' => 0.2,
            'trim_end' => 2.7,
        ]);
        $source = storage('public')->path($video->storage_path);
        File::ensureDirectoryExists(dirname($source));
        Process::run([
            $tools['ffmpeg'],
            '-v',
            'error',
            '-f',
            'lavfi',
            '-i',
            'color=s=64x64:d=3',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=220:duration=0.5',
            $source,
        ])->throw();
        $result = app(MediaProcessor::class)->video($video);
        $output = storage('public')->path($result['storage_path']);
        $pcmPath = $this->storagePath . '/samples.pcm';
        Process::run([
            $tools['ffmpeg'],
            '-v',
            'error',
            '-i',
            $output,
            '-t',
            '0.3',
            '-vn',
            '-ac',
            '1',
            '-ar',
            '48000',
            '-f',
            's16le',
            $pcmPath,
        ])->throw();
        $pcm = file_get_contents($pcmPath);
        $samples = array_map(fn($n) => $n > 32767 ? $n - 65536 : $n, array_values(unpack('v*', $pcm)));
        $rms = sqrt(array_sum(array_map(fn($n) => $n * $n, $samples)) / count($samples));
        $this->assertTrue($rms > 500 && $rms < 1800);
        $probe = json_decode(Process::run([
            $tools['ffprobe'],
            '-v',
            'error',
            '-show_format',
            '-show_streams',
            '-of',
            'json',
            $output,
        ])->throw()->output(), true);
        $this->assertTrue(abs((float) $probe['format']['duration'] - 2.5) < 0.1);
        $audioStream = array_values(array_filter($probe['streams'], fn($stream) => $stream['codec_type'] === 'audio'))[0];
        $this->assertTrue((float) $audioStream['duration'] >= 2.4);
        $video->audio_settings = ['start' => 3];
        $this->assertThrows(\RuntimeException::class, fn() => app(MediaProcessor::class)->video($video));
    }
}
