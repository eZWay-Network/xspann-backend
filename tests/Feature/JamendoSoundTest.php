<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideo;
use App\Models\{Audio, Video};
use App\Services\MediaProcessor;
use Spark\Console\Process;
use Spark\Http\Client\{Http, HttpResponse};
use Spark\Http\Client\Contracts\HttpResponseContract;
use Tests\TestCase;

class JamendoSoundTest extends TestCase
{
    private Http $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->mergeConfig(['app' => ['jamendo_client_id' => 'test-client']]);
        $this->http = new class extends Http {
            public int $calls = 0;
            public int $status = 200;
            public bool $offline = false;
            public array $parameters = [];
            public array $tracks = [];

            public function get(string $url, array $params = []): HttpResponseContract
            {
                if ($url !== 'https://api.jamendo.com/v3.0/tracks/') {
                    throw new \LogicException('Unexpected provider URL.');
                }
                $this->calls++;
                $this->parameters = $params;
                if ($this->offline) {
                    throw new \RuntimeException('Offline');
                }
                $tracks = isset($params['id'])
                    ? array_values(array_filter($this->tracks, fn($track) => $track['id'] === $params['id']))
                    : $this->tracks;

                return new HttpResponse(body: json_encode([
                    'headers' => ['status' => $this->status === 200 ? 'success' : 'failed'],
                    'results' => $tracks,
                ]), status: $this->status);
            }
        };
        $this->http->tracks = [
            [
                'id' => '123',
                'name' => 'Provider title',
                'artist_name' => 'Provider artist',
                'artist_id' => '42',
                'audio' => 'https://media.example.com/123.mp3',
                'duration' => 120,
                'audiodownload_allowed' => true,
                'license_ccurl' => 'https://creativecommons.org/licenses/by/4.0/',
            ]
        ];
        $this->app->instance(Http::class, $this->http);
    }

    public function testSearchProfilesCachingAndProviderErrors(): void
    {
        $this->http->tracks[] = [...$this->http->tracks[0], 'id' => '456', 'audiodownload_allowed' => false];
        $this->getJson('/api/v1/sounds/jamendo?q=hello&limit=2&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.provider', 'jamendo')
            ->assertJsonPath('data.0.external_id', '123')->assertJsonPath('meta.has_more', true);
        $this->assertSame(2, $this->http->parameters['offset']);
        $this->assertSame('hello', $this->http->parameters['search']);
        $this->getJson('/api/v1/sounds/jamendo?q=hello&limit=2&page=2')->assertOk();
        $this->assertSame(1, $this->http->calls);
        $this->getJson('/api/v1/sounds/jamendo/123')->assertOk()
            ->assertJsonPath('data.preview_url', 'https://media.example.com/123.mp3');
        $this->getJson('/api/v1/sounds/jamendo/456')->assertStatus(404);
        $this->getJson('/api/v1/sounds/jamendo/abc')->assertStatus(404);
        $this->getJson('/api/v1/sounds/jamendo?limit=61')->assertStatus(422);
        $this->http->status = 500;
        $this->getJson('/api/v1/sounds/jamendo?q=failed')->assertStatus(503);
        $this->http->offline = true;
        $this->getJson('/api/v1/sounds/jamendo?q=offline')->assertStatus(503);
        $this->app->mergeConfig(['app' => ['jamendo_client_id' => null]]);
        $this->getJson('/api/v1/sounds/jamendo')->assertStatus(503);
        $this->assertSame(0, Audio::count());
    }

    public function testVideoUsesCanonicalProviderMetadataAndRequiresClientMix(): void
    {
        $user = $this->makeUser();
        $this->asUser($user);
        storage('public')->put('videos/1/upload.mp4', 'upload');
        $input = ['storage_path' => 'videos/1/upload.mp4', 'sound_provider' => 'jamendo', 'sound_external_id' => '123'];
        $this->postJson('/api/v1/videos', $input)->assertStatus(422)->assertJsonValidationErrors(['audio_settings.rendered']);
        $input['audio_settings'] = ['rendered' => true];
        $this->postJson('/api/v1/videos', [...$input, 'reuse_content' => 'invalid'])->assertStatus(422);
        $this->postJson('/api/v1/videos', [...$input, 'audio_id' => 1])->assertStatus(422);
        $this->postJson('/api/v1/videos', [...$input, 'sound_external_id' => '999'])->assertStatus(422);
        $id = $this->postJson('/api/v1/videos', [...$input, 'sound_name' => 'Forged', 'sound_preview_url' => 'https://evil.example/test'])
            ->assertStatus(201)->assertJsonPath('data.sound_name', 'Provider title')
            ->assertJsonPath('data.sound_artist', 'Provider artist')->assertJsonPath('data.sound_external_id', '123')
            ->assertJsonPath('data.sound_preview_url', 'https://media.example.com/123.mp3')
            ->assertJsonPath('data.reuse_content', true)->assertJsonPath('data.audio_id', null)->json('data.id');
        $this->patchJson('/api/v1/videos/' . $id, ['sound_name' => 'Changed'])->assertStatus(422);
        $this->patchJson('/api/v1/videos/' . $id, ['caption' => 'Updated', 'reuse_content' => false])->assertOk();
        $this->postJson('/api/v1/videos', ['storage_path' => 'videos/1/upload.mp4', 'reuse_content' => false])
            ->assertStatus(201)->assertJsonPath('data.reuse_content', false);
        $this->assertSame(0, Audio::count());
    }

    public function testProfileVideosUseProviderIdAndVisibilityWithoutProviderCalls(): void
    {
        $owner = $this->makeUser();
        $this->makeVideo($owner, ['sound_provider' => 'jamendo', 'sound_external_id' => '123']);
        $this->makeVideo($owner, ['sound_provider' => 'jamendo', 'sound_external_id' => '123', 'visibility' => 'private']);
        $this->makeVideo($owner, ['sound_provider' => 'local', 'sound_external_id' => '123']);
        $this->makeVideo($owner, ['sound_provider' => 'jamendo', 'sound_external_id' => '456']);
        $this->getJson('/api/v1/sounds/jamendo/123/videos')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(0, $this->http->calls);
    }

    public function testRevokingReuseHidesOriginalSoundsAndPreventsPublicationRaces(): void
    {
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner);
        $audio = Audio::create(['user_id' => $owner->id, 'title' => 'Original', 'origin' => 'original', 'source_video_id' => $video->id, 'storage_path' => 'sounds/1/a.m4a', 'status' => 'ready']);
        $this->asUser($owner)->patchJson('/api/v1/videos/' . $video->id, ['reuse_content' => false])->assertOk();
        $this->flushHeaders()->getJson('/api/v1/audios/' . $audio->id)->assertStatus(404);
        $this->getJson('/api/v1/audios')->assertJsonCount(0, 'data');
        $this->asUser($owner)->patchJson('/api/v1/videos/' . $video->id, ['reuse_content' => true])->assertOk();
        $this->flushHeaders()->getJson('/api/v1/audios/' . $audio->id)->assertOk();

        $pending = $this->makeVideo($owner, ['status' => 'processing']);
        $this->app->instance(MediaProcessor::class, new class extends MediaProcessor {
            public function video(Video $video): array
            {
                storage('public')->put('videos/1/encoded.mp4', 'video');
                storage('public')->put('sounds/1/extracted.m4a', 'audio');
                $video->update(['reuse_content' => false]);

                return ['storage_path' => 'videos/1/encoded.mp4', 'audio_path' => 'sounds/1/extracted.m4a', 'duration' => 1];
            }
        });
        (new ProcessVideo($pending->id))->handle();
        $this->assertSame(null, $pending->refresh()->audio_id);
        $this->assertFalse(storage('public')->exists('sounds/1/extracted.m4a'));
        $this->assertSame('published', $pending->status);
    }

    public function testRealProcessingHonoursOptOutAndPreservesClientMixedJamendoAudio(): void
    {
        $tools = [];
        foreach (['ffmpeg', 'ffprobe'] as $binary) {
            foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
                if (is_executable("$directory/$binary")) {
                    $tools[$binary] = "$directory/$binary";
                    break;
                }
            }
        }
        if (count($tools) !== 2) {
            $this->markTestSkipped('FFmpeg and FFprobe are required.');
        }
        $this->app->mergeConfig(['app' => $tools]);
        $owner = $this->makeUser();
        $source = $this->storagePath . '/mixed.mp4';
        Process::run([
            $tools['ffmpeg'],
            '-v',
            'error',
            '-f',
            'lavfi',
            '-i',
            'color=s=90x160:d=0.5',
            '-f',
            'lavfi',
            '-i',
            'sine=duration=0.5',
            '-c:v',
            'libx264',
            '-c:a',
            'aac',
            $source,
        ])->throw();
        foreach ([['reuse_content' => false], ['sound_provider' => 'jamendo', 'sound_external_id' => '123', 'audio_settings' => ['rendered' => true], 'original_audio_muted' => true]] as $index => $settings) {
            $path = "videos/1/clip-$index.mp4";
            storage('public')->putFileAs('videos/1', $source, basename($path));
            $video = $this->makeVideo($owner, ['storage_path' => $path, 'status' => 'processing', ...$settings]);
            (new ProcessVideo($video->id))->handle();
            $this->assertSame('published', $video->refresh()->status);
            $this->assertSame(null, $video->audio_id);
            $probe = json_decode(Process::run([$tools['ffprobe'], '-v', 'error', '-show_streams', '-of', 'json', storage('public')->path($video->storage_path)])->throw()->output(), true);
            $this->assertTrue(in_array('audio', array_column($probe['streams'], 'codec_type'), true));
        }
        $this->assertSame(0, Audio::count());

        $silent = $this->storagePath . '/silent.mp4';
        Process::run([$tools['ffmpeg'], '-v', 'error', '-i', $source, '-an', '-c:v', 'copy', $silent])->throw();
        storage('public')->putFileAs('videos/1', $silent, 'silent.mp4');
        $video = $this->makeVideo($owner, [
            'storage_path' => 'videos/1/silent.mp4',
            'status' => 'processing',
            'sound_provider' => 'jamendo',
            'sound_external_id' => '123',
            'audio_settings' => ['rendered' => true],
        ]);
        $failed = false;
        try {
            (new ProcessVideo($video->id))->handle();
        } catch (\RuntimeException $exception) {
            $failed = true;
            $this->assertSame('Jamendo shorts must contain client-rendered audio.', $exception->getMessage());
        }
        $this->assertTrue($failed);
        $this->assertSame('processing', $video->refresh()->status);
    }
}
