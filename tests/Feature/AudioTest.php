<?php

namespace Tests\Feature;

use App\Jobs\{DeleteVideo, ProcessAudio, ProcessVideo};
use App\Models\{Audio, Block, Video};
use Spark\Console\Process;
use Spark\Database\Schema\Schema;
use Tests\TestCase;

class AudioTest extends TestCase
{
    private function track(int $userId, array $attributes = []): Audio
    {
        return Audio::create([
            'user_id' => $userId,
            'title' => 'A reusable sound',
            'storage_path' => "sounds/$userId/track.m4a",
            'status' => 'ready',
            'duration' => 5,
            ...$attributes,
        ])->refresh();
    }

    public function testLibraryProfilesAndSoundSelectionKeepTheOriginalCreator(): void
    {
        $creator = $this->makeUser();
        $viewer = $this->makeUser('bob');
        $track = $this->track($creator->id);
        $this->getJson('/api/v1/audios?q=reusable')->assertOk()
            ->assertJsonPath('data.0.creator.id', $creator->id)
            ->assertJsonPath('data.0.audio_url', 'http://localhost:8080/uploads/sounds/1/track.m4a');
        $this->getJson('/api/v1/audios/' . $track->id)->assertOk()->assertJsonPath('data.title', $track->title);

        storage('public')->put('videos/2/upload.mp4', 'uploaded');
        $id = $this->asUser($viewer)->postJson('/api/v1/videos', [
            'storage_path' => 'videos/2/upload.mp4',
            'audio_id' => $track->id,
            'sound_name' => 'Forged title',
        ])->assertStatus(201)->assertJsonPath('data.audio.creator.id', $creator->id)
            ->assertJsonPath('data.audio_id', $track->id)
            ->assertJsonPath('data.audio_mode', 'replace')
            ->assertJsonPath('data.sound_name', $track->title)->json('data.id');
        $this->patchJson('/api/v1/videos/' . $id, ['audio_id' => $track->id])->assertStatus(422);
        Video::whereKey($id)->update(['status' => 'published']);
        $this->getJson('/api/v1/audios/' . $track->id . '/videos')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/audios/' . $track->id)->assertJsonPath('data.videos_count', 1);
    }

    public function testUnavailableAndPrivateOriginalSoundsCannotBeSelected(): void
    {
        $creator = $this->makeUser();
        $viewer = $this->makeUser('bob');
        $source = $this->makeVideo($creator, ['visibility' => 'private']);
        $track = $this->track($creator->id, ['origin' => 'original', 'source_video_id' => $source->id]);
        $this->getJson('/api/v1/audios')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/audios/' . $track->id)->assertStatus(404);
        storage('public')->put('videos/2/upload.mp4', 'uploaded');
        $this->asUser($viewer)->postJson('/api/v1/videos', ['storage_path' => 'videos/2/upload.mp4', 'audio_id' => $track->id])
            ->assertStatus(422)->assertJsonValidationErrors(['audio_id']);
        $source->update(['visibility' => 'public']);
        $this->getJson('/api/v1/audios')->assertJsonCount(1, 'data');
        $source->update(['visibility' => 'followers']);
        $this->getJson('/api/v1/audios')->assertJsonCount(0, 'data');
        $source->update(['visibility' => 'public']);
        Block::create(['blocker_id' => $creator->id, 'blocked_id' => $viewer->id]);
        $this->getJson('/api/v1/audios/' . $track->id)->assertStatus(404);
        $this->postJson('/api/v1/videos', ['storage_path' => 'videos/2/upload.mp4', 'audio_id' => 99999])->assertStatus(422);
        $this->postJson('/api/v1/videos', ['storage_path' => 'videos/2/upload.mp4', 'audio_mode' => 'invalid'])->assertStatus(422);
    }

    public function testProcessingUploadsAreVisibleOnlyToTheirOwnerAndCannotBeUsed(): void
    {
        $owner = $this->makeUser();
        $track = $this->track($owner->id, ['status' => 'processing']);
        $this->getJson('/api/v1/audios')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/audios/' . $track->id)->assertStatus(404);
        $this->asUser($owner)->getJson('/api/v1/audios/' . $track->id)->assertOk()
            ->assertJsonPath('data.status', 'processing')->assertJsonPath('data.audio_url', null);
        storage('public')->put('videos/1/upload.mp4', 'uploaded');
        $this->postJson('/api/v1/videos', ['storage_path' => 'videos/1/upload.mp4', 'audio_id' => $track->id])->assertStatus(422);
    }

    public function testDeletingTheOriginalHidesItsSoundWithoutDeletingReusedVideos(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser('bob');
        $source = $this->makeVideo($owner);
        $track = $this->track($owner->id, ['origin' => 'original', 'source_video_id' => $source->id]);
        $source->update(['audio_id' => $track->id]);
        $reused = $this->makeVideo($other, ['audio_id' => $track->id]);
        storage('public')->put($track->storage_path, 'sound');
        storage('public')->put($reused->storage_path, 'reused-video');
        $this->asUser($owner)->deleteJson('/api/v1/videos/' . $source->id)->assertOk();
        $this->asUser($other)->getJson('/api/v1/audios/' . $track->id)->assertStatus(404);
        $this->getJson('/api/v1/videos/' . $reused->id)->assertOk()->assertJsonPath('data.audio', null);
        (new DeleteVideo($source->id))->handle();
        $this->assertDatabaseMissing('audios', ['id' => $track->id]);
        $this->assertSame(null, $reused->refresh()->audio_id);
        $this->assertTrue(storage('public')->exists($reused->storage_path));
        $this->assertFalse(storage('public')->exists($track->storage_path));
    }

    public function testLibraryAndFeedQueriesDoNotGrowWithMoreTracks(): void
    {
        for ($index = 0; $index < 6; $index++) {
            $user = $this->makeUser('creator' . $index);
            $track = $this->track($user->id);
            $this->makeVideo($user, ['audio_id' => $track->id]);
        }
        $queries = 0;
        event()->addListener('app:db.queryExecuted', function () use (&$queries): void {
            $queries++;
        });
        foreach (['/api/v1/feed', '/api/v1/audios'] as $route) {
            $queries = 0;
            $this->getJson($route . '?limit=1')->assertOk()->assertJsonCount(1, 'data');
            $small = $queries;
            $queries = 0;
            $this->getJson($route . '?limit=6')->assertOk()->assertJsonCount(6, 'data');
            $this->assertSame($small, $queries);
        }
    }

    public function testMigrationRollbackAndForeignKeysPreserveExistingVideos(): void
    {
        $user = $this->makeUser();
        $video = $this->makeVideo($user);
        $audio = $this->track($user->id);
        $video->update(['audio_id' => $audio->id]);
        $audio->delete();
        $this->assertSame(null, $video->refresh()->audio_id);
        $migration = require dirname(__DIR__, 2) . '/database/migrations/migration_2026_10_05_120000_audio_library.php';
        $migration->down();
        $this->assertFalse(Schema::hasTable('audios'));
        $this->assertDatabaseHas('videos', ['id' => $video->id]);
        $migration->up();
        $this->assertTrue(Schema::hasColumn('videos', 'audio_id'));
    }

    public function testRealEncodingExtractsAndReusesAudioWithReplaceAndMix(): void
    {
        $binaries = $this->realTools();
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner, ['status' => 'processing']);
        $source = storage('public')->path($video->storage_path);
        \Spark\Utils\File::ensureDirectoryExists(dirname($source));
        Process::run([
            $binaries['ffmpeg'],
            '-nostdin',
            '-y',
            '-v',
            'error',
            '-f',
            'lavfi',
            '-i',
            'color=c=black:s=1080x1920:d=0.6',
            '-f',
            'lavfi',
            '-i',
            'sine=frequency=440:duration=0.6',
            '-c:v',
            'mpeg4',
            '-c:a',
            'aac',
            '-shortest',
            $source,
        ], timeout: 30)->throw();
        $this->app->mergeConfig(['app' => $binaries]);
        (new ProcessVideo($video->id))->handle();
        $video->refresh();
        $this->assertSame('published', $video->status);
        $this->assertFalse(is_file($source));
        $output = storage('public')->path($video->storage_path);
        $probe = json_decode(Process::run([
            $binaries['ffprobe'],
            '-v',
            'error',
            '-show_streams',
            '-of',
            'json',
            $output,
        ])->throw()->output(), true);
        $this->assertSame('h264', $probe['streams'][0]['codec_name']);
        $this->assertSame(720, $probe['streams'][0]['width']);
        $this->assertSame(1280, $probe['streams'][0]['height']);
        $this->assertSame('yuv420p', $probe['streams'][0]['pix_fmt']);
        $this->assertSame('30/1', $probe['streams'][0]['r_frame_rate']);
        $this->assertSame('aac', $probe['streams'][1]['codec_name']);
        $contents = file_get_contents($output);
        $this->assertTrue(strpos($contents, 'moov') < strpos($contents, 'mdat'));
        $track = Audio::find($video->audio_id);
        $this->assertSame($video->id, $track->source_video_id);
        $this->assertSame('ready', $track->status);
        (new ProcessVideo($video->id))->handle();
        $this->assertDatabaseCount('audios', 1);

        foreach (['replace', 'mix'] as $mode) {
            $path = "videos/1/$mode.mp4";
            storage('public')->put($path, $contents);
            $reused = $this->makeVideo($owner, ['storage_path' => $path, 'status' => 'processing', 'audio_id' => $track->id, 'audio_mode' => $mode]);
            (new ProcessVideo($reused->id))->handle();
            $this->assertSame('published', $reused->refresh()->status);
            $this->assertSame($track->id, $reused->audio_id);
            Process::run([$binaries['ffmpeg'], '-v', 'error', '-i', storage('public')->path($reused->storage_path), '-f', 'null', '-'], timeout: 30)->throw();
        }
        $this->assertDatabaseCount('audios', 1);
        $this->assertSame([], glob($this->storagePath . '/temp/media-processing/*'));
    }

    public function testRealAudioUploadProcessingAndMutedVideoDoNotCreateDuplicateTracks(): void
    {
        $binaries = $this->realTools();
        $this->app->mergeConfig(['app' => $binaries]);
        $owner = $this->makeUser();
        $audio = $this->track($owner->id, ['status' => 'processing', 'storage_path' => 'sounds/1/source.wav']);
        $source = storage('public')->path($audio->storage_path);
        \Spark\Utils\File::ensureDirectoryExists(dirname($source));
        Process::run([$binaries['ffmpeg'], '-v', 'error', '-f', 'lavfi', '-i', 'sine=duration=0.5', $source])->throw();
        (new ProcessAudio($audio->id))->handle();
        $this->assertSame('ready', $audio->refresh()->status);
        $this->assertFalse(is_file($source));
        (new ProcessAudio($audio->id))->handle();
        $this->assertDatabaseCount('audios', 1);

        $video = $this->makeVideo($owner, ['status' => 'processing', 'original_audio_muted' => true]);
        $source = storage('public')->path($video->storage_path);
        \Spark\Utils\File::ensureDirectoryExists(dirname($source));
        Process::run([$binaries['ffmpeg'], '-v', 'error', '-f', 'lavfi', '-i', 'color=s=32x32:d=0.5', '-f', 'lavfi', '-i', 'sine=duration=0.5', '-shortest', $source])->throw();
        (new ProcessVideo($video->id))->handle();
        $this->assertSame('published', $video->refresh()->status);
        $this->assertSame(null, $video->audio_id);
        $this->assertDatabaseCount('audios', 1);
        $probe = json_decode(Process::run([
            $binaries['ffprobe'],
            '-v',
            'error',
            '-show_streams',
            '-of',
            'json',
            storage('public')->path($video->storage_path),
        ])->throw()->output(), true);
        $this->assertSame(['video'], array_column($probe['streams'], 'codec_type'));
    }

    public function testDeletingAShortPreservesIndependentlyUploadedLibraryAudio(): void
    {
        $owner = $this->makeUser();
        $track = $this->track($owner->id);
        $video = $this->makeVideo($owner, [
            'audio_id' => $track->id,
            'sound_preview_url' => $track->storage_path,
            'status' => 'deleted',
        ]);
        storage('public')->put($track->storage_path, 'library audio');
        (new DeleteVideo($video->id))->handle();
        $this->assertDatabaseHas('audios', ['id' => $track->id]);
        $this->assertTrue(storage('public')->exists($track->storage_path));
    }

    public function testDeletingAnOriginalPreservesItsFileWhileAnotherAudioUsesIt(): void
    {
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner, ['status' => 'deleted']);
        $original = $this->track($owner->id, ['origin' => 'original', 'source_video_id' => $video->id]);
        $copy = $this->track($owner->id, ['storage_path' => $original->storage_path, 'status' => 'processing']);
        storage('public')->put($original->storage_path, 'shared audio');

        (new DeleteVideo($video->id))->handle();

        $this->assertDatabaseMissing('audios', ['id' => $original->id]);
        $this->assertDatabaseHas('audios', ['id' => $copy->id]);
        $this->assertTrue(storage('public')->exists($copy->storage_path));
    }

    public function testPublishingPreservesAThumbnailChangedDuringEncoding(): void
    {
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner, ['status' => 'processing']);
        $this->app->instance(\App\Services\MediaProcessor::class, new class extends \App\Services\MediaProcessor {
            public function video(Video $video): array
            {
                foreach (['videos/1/encoded.mp4', 'thumbnails/1/generated.jpg', 'thumbnails/1/chosen.jpg'] as $path) {
                    storage('public')->put($path, 'media');
                }
                Video::whereKey($video->id)->update(['thumbnail_url' => 'thumbnails/1/chosen.jpg']);

                return ['storage_path' => 'videos/1/encoded.mp4', 'thumbnail_url' => 'thumbnails/1/generated.jpg', 'duration' => 10];
            }
        });

        (new ProcessVideo($video->id))->handle();

        $this->assertSame('thumbnails/1/chosen.jpg', $video->refresh()->thumbnail_url);
        $this->assertTrue(storage('public')->exists('thumbnails/1/chosen.jpg'));
        $this->assertFalse(storage('public')->exists('thumbnails/1/generated.jpg'));
        $this->assertSame('published', $video->status);
    }

    public function testDeletionDuringEncodingCleansUnpublishedOutputs(): void
    {
        $user = $this->makeUser();
        $video = $this->makeVideo($user, ['status' => 'processing']);
        $this->app->instance(\App\Services\MediaProcessor::class, new class extends \App\Services\MediaProcessor {
            public function video(Video $video): array
            {
                foreach (['videos/1/encoded.mp4', 'sounds/1/extracted.m4a', 'thumbnails/1/cover.jpg'] as $path) {
                    storage('public')->put($path, 'generated');
                }
                Video::whereKey($video->id)->update(['status' => 'deleted']);

                return [
                    'storage_path' => 'videos/1/encoded.mp4',
                    'audio_path' => 'sounds/1/extracted.m4a',
                    'thumbnail_url' => 'thumbnails/1/cover.jpg',
                    'duration' => 2,
                ];
            }
        });
        (new ProcessVideo($video->id))->handle();
        $this->assertSame('deleted', $video->refresh()->status);
        $this->assertDatabaseCount('audios', 0);
        foreach (['videos/1/encoded.mp4', 'sounds/1/extracted.m4a', 'thumbnails/1/cover.jpg'] as $path) {
            $this->assertFalse(storage('public')->exists($path));
        }
    }

    public function testInvalidAudioFailsWithoutPublishingOrRemovingTheUpload(): void
    {
        $owner = $this->makeUser();
        $audio = $this->track($owner->id, ['status' => 'processing']);
        storage('public')->put($audio->storage_path, 'not audio');
        ProcessAudio::dispatch($audio->id)->send();
        app(\Spark\Queue\Queue::class)->work(once: true, timeout: 5, sleep: 0);
        $this->assertSame('failed', $audio->refresh()->status);
        $this->assertTrue(storage('public')->exists($audio->storage_path));
        $this->getJson('/api/v1/audios')->assertJsonCount(0, 'data');
        $this->assertSame([], glob($this->storagePath . '/temp/media-processing/*') ?: []);
    }

    public function testLibrarySearchTreatsWildcardsLiterallyAndValidatesQueries(): void
    {
        $owner = $this->makeUser();
        $track = $this->track($owner->id, ['title' => '100% original_sound']);
        $this->track($owner->id, ['title' => 'A different sound']);
        $this->getJson('/api/v1/audios?q=' . rawurlencode('%'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $track->id);
        $this->getJson('/api/v1/audios?q=' . rawurlencode('_'))->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/audios?q=' . str_repeat('a', 101))->assertStatus(422);
        $this->getJson('/api/v1/audios/99999')->assertStatus(404);
    }

    private function realTools(): array
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
            $this->markTestSkipped('Install FFmpeg and FFprobe for real media integration tests.');
        }

        return $binaries;
    }
}
