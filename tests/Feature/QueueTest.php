<?php

namespace Tests\Feature;

use App\Jobs\{DeleteVideo, ProcessAudio, ProcessVideo, SendAccountEmail};
use App\Models\Video;
use App\Services\{StorageService, VideoMetadataExtractor, AccountNotifications, MediaProcessor};
use Spark\Queue\Queue;
use Tests\TestCase;

class QueueTest extends TestCase
{
    public function testJobsForMissingRecordsCompleteWithoutRetrying(): void
    {
        ProcessVideo::dispatch(98765)->send();
        ProcessAudio::dispatch(98765)->send();
        DeleteVideo::dispatch(98765)->send();
        SendAccountEmail::dispatch(98765, 'Verify your email address', 'Verify your account.')->send();

        $queue = app(Queue::class);

        for ($i = 0; $i < 4; $i++) {
            $queue->work(once: true, timeout: 5, sleep: 0, queue: 'default');
        }

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('videos', 0);
        $this->assertDatabaseCount('audios', 0);
    }

    public function testVideoJobPublishesProcessedDurationAndIsIdempotent(): void
    {
        $video = $this->makeVideo($this->makeUser(), ['status' => 'processing', 'duration' => 20, 'thumbnail_url' => 'https://cdn.example.com/cover.jpg']);
        $extractor = new class extends MediaProcessor {
            public int $calls = 0;
            public function video(Video $video): array
            {
                $this->calls++;
                return ['storage_path' => $video->storage_path, 'duration' => 12, 'thumbnail_url' => 'https://cdn.example.com/new.jpg'];
            }
        };
        $this->app->instance(MediaProcessor::class, $extractor);
        ProcessVideo::dispatch((int) $video->id)->send();
        app(Queue::class)->work(once: true, timeout: 5, sleep: 0, queue: 'default');
        $this->assertDatabaseHas('videos', ['id' => $video->id, 'status' => 'published', 'duration' => 12, 'thumbnail_url' => 'https://cdn.example.com/cover.jpg']);
        (new ProcessVideo((int) $video->id))->handle();
        $this->assertSame(1, $extractor->calls);
        $this->assertSame(0, (int) app(Queue::class)->getConnection()->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
    }

    public function testWorkerMarksUnexpectedProcessingFailureFailed(): void
    {
        $video = $this->makeVideo($this->makeUser(), ['status' => 'processing']);
        $this->app->instance(MediaProcessor::class, new class extends MediaProcessor {
            public function video(Video $video): array
            {
                throw new \RuntimeException('Decoder unavailable');
            }
        });
        ProcessVideo::dispatch((int) $video->id)->send();
        $queue = app(Queue::class);
        $queue->work(once: true, timeout: 5, sleep: 0, queue: 'default');
        $this->assertSame('failed', $video->refresh()->status);
        $this->assertSame('failed', $queue->getConnection()->query('SELECT status FROM jobs')->fetchColumn());
    }

    public function testMissingLocalUploadCannotPublish(): void
    {
        $video = $this->makeVideo($this->makeUser(), ['status' => 'processing']);
        ProcessVideo::dispatch((int) $video->id)->send();
        app(Queue::class)->work(once: true, timeout: 5, sleep: 0);
        $this->assertSame('failed', $video->refresh()->status);
        $this->getJson('/api/v1/feed')->assertOk()->assertJsonPath('data', []);
    }

    public function testConcurrentDeletionCannotBeRepublished(): void
    {
        $video = $this->makeVideo($this->makeUser(), ['status' => 'processing']);
        $this->app->instance(MediaProcessor::class, new class extends MediaProcessor {
            public function video(Video $video): array
            {
                query('videos')->where('id', $video->id)->update(['status' => 'deleted']);
                return ['storage_path' => $video->storage_path, 'duration' => 9];
            }
        });
        (new ProcessVideo((int) $video->id))->handle();
        $this->assertSame('deleted', $video->refresh()->status);
        (new ProcessVideo((int) $video->id))->failed(new \RuntimeException('Late failure'));
        $this->assertSame('deleted', $video->refresh()->status);
    }

    public function testExtractorUsesNativeProcessAndCleansTemporaryFiles(): void
    {
        $user = $this->makeUser();
        $video = $this->makeVideo($user, ['cover_time' => 100]);
        storage('public')->put($video->storage_path, 'fixture-video');
        $this->fakeVideoTools();
        $result = (new VideoMetadataExtractor())->extract($video);
        $this->assertSame(13, $result['duration']);
        $this->assertSame($result['thumbnail_path'], $result['thumbnail_url']);
        $this->assertSame('fixture-thumbnail', storage('public')->get($result['thumbnail_path']));
        $arguments = json_decode(file_get_contents($this->storagePath . '/arguments.json'), true);
        $this->assertSame('12.15', $arguments[array_search('-ss', $arguments) + 1]);
        $this->assertSame('file,pipe', $arguments[array_search('-protocol_whitelist', $arguments) + 1]);
        $this->assertSame([], glob($this->storagePath . '/temp/video-processing/*'));
    }

    public function testExtractorFailureLeavesNoTemporaryFiles(): void
    {
        $video = $this->makeVideo($this->makeUser());
        storage('public')->put($video->storage_path, 'bad-video');
        $this->app->mergeConfig(['app' => ['ffprobe' => '/nonexistent/ffprobe', 'ffmpeg' => '/nonexistent/ffmpeg']]);
        $this->assertSame([], (new VideoMetadataExtractor())->extract($video));
        $video->fill(['status' => 'processing']);
        $video->save();
        $this->assertThrows(\Throwable::class, fn () => (new ProcessVideo((int) $video->id))->handle());
        $this->assertSame('processing', $video->refresh()->status);
        $this->assertSame([], glob($this->storagePath . '/temp/video-processing/*') ?: []);
    }

    public function testDefaultCoverTimeStaysInsideShortVideos(): void
    {
        $video = $this->makeVideo($this->makeUser());
        storage('public')->put($video->storage_path, 'fixture-video');
        $this->fakeVideoTools('0.52');

        $metadata = (new VideoMetadataExtractor())->extract($video);
        $arguments = json_decode(file_get_contents($this->storagePath . '/arguments.json'), true);

        $this->assertSame('0.42', $arguments[array_search('-ss', $arguments) + 1]);
        $this->assertSame(1, $metadata['duration']);
        $this->assertTrue(storage('public')->exists($metadata['thumbnail_path']));
        $this->assertSame([], glob($this->storagePath . '/temp/video-processing/*'));
    }

    public function testQueuedAccountEmailUsesNativeMailerAndValidResetToken(): void
    {
        $user = $this->makeUser();
        $mail = new class extends \Spark\Utils\Mail {
            public function send(): bool
            {
                return $this->preSend();
            }
        };
        $this->app->instance(\Spark\Utils\Mail::class, $mail);
        (new AccountNotifications())->reset($user);
        app(Queue::class)->work(once: true, timeout: 5, sleep: 0, queue: 'default');
        $this->assertSame('Reset your password', $mail->Subject);
        $this->assertTrue(str_contains($mail->getSentMIMEMessage(), 'alice@example.com'));
        preg_match('/token=([^&\s]+)/', $mail->Body, $matches);
        $this->assertTrue(password_verify(urldecode($matches[1]), query('password_reset_tokens')->where('email', $user->email)->value('token')));
    }

    public function testRegistrationQueuesADeliverableVerificationLink(): void
    {
        $mail = new class extends \Spark\Utils\Mail {
            public function send(): bool
            {
                return $this->preSend();
            }
        };
        $this->app->instance(\Spark\Utils\Mail::class, $mail);
        $this->postJson('/api/v1/auth/register', [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);
        app(Queue::class)->work(once: true, timeout: 5, sleep: 0);
        $this->assertSame('Verify your email address', $mail->Subject);
        $this->assertTrue(str_contains($mail->getSentMIMEMessage(), 'alice@example.com'));
        preg_match('~href="([^"]+/auth/email/verify/[^"]+)"~', $mail->Body, $matches);
        $this->assertTrue(isset($matches[1]));
        $this->getJson(html_entity_decode($matches[1]))->assertOk();
        $this->assertTrue(\App\Models\User::find(1)->hasVerifiedEmail());
    }
    public function testFailedVideoCanBeExplicitlyRetried(): void
    {
        $video = $this->makeVideo($this->makeUser(), ['status' => 'failed']);
        $retry = new \App\Services\VideoRetry();
        $this->assertTrue($retry->retry((int) $video->id));
        $this->assertSame('processing', $video->refresh()->status);
        $video->fill(['status' => 'deleted']);
        $video->save();
        $this->assertFalse($retry->retry((int) $video->id));
        $this->assertSame('deleted', $video->refresh()->status);
    }

    public function testRealFfmpegExtractsAPlayableVideoWhenInstalled(): void
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
            $this->markTestSkipped('Install FFmpeg and FFprobe to run the real decoder integration.');
        }
        $video = $this->makeVideo($this->makeUser());
        $source = storage('public')->path($video->storage_path);
        \Spark\Utils\File::ensureDirectoryExists(dirname($source));
        \Spark\Console\Process::run([
            $binaries['ffmpeg'],
            '-nostdin',
            '-y',
            '-f',
            'lavfi',
            '-i',
            'color=c=black:s=32x32:d=0.5',
            '-c:v',
            'mpeg4',
            $source,
        ], timeout: 15)->throw();
        $this->app->mergeConfig(['app' => $binaries]);
        $metadata = (new VideoMetadataExtractor())->extract($video);
        $this->assertSame(1, $metadata['duration']);
        $image = storage('public')->path($metadata['thumbnail_path']);
        $this->assertSame('image/jpeg', mime_content_type($image));
        $this->assertSame(720, getimagesize($image)[0]);
    }

}
