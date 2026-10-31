<?php

namespace Tests\Feature;

use App\Jobs\{DeleteAudio, DeleteVideo, ProcessVideo};
use App\Models\{Audio, Comment, Notification, Video};
use App\Services\{MediaProcessor, VideoRetry};
use Tests\TestCase;

class FoundationAuditTest extends TestCase
{
    public function testVideoDetailRejectsUnpublishedStatusAfterRouteBinding(): void
    {
        $video = $this->makeVideo($this->makeUser());
        $this->app->mergeConfig(['app' => ['debug' => true]]);
        $pendingStatus = null;

        event()->addListener('app:db.queryExecuted', function (string $query) use ($video, &$pendingStatus): void {
            if ($pendingStatus && str_starts_with($query, 'SELECT') && str_contains($query, 'videos')) {
                $status = $pendingStatus;
                $pendingStatus = null;
                $video->update(['status' => $status]);
            }
        });

        foreach ([Video::STATUS_PROCESSING, Video::STATUS_DELETED] as $status) {
            $video->update(['status' => Video::STATUS_PUBLISHED]);
            $pendingStatus = $status;
            $this->getJson('/api/v1/videos/' . $video->id)->assertStatus(404);
            $this->assertSame(null, $pendingStatus);
        }
    }

    public function testAudioOwnerDetailRejectsDeletionAfterRouteBinding(): void
    {
        $owner = $this->makeUser();
        $audio = Audio::create(['user_id' => $owner->id, 'title' => 'Sound', 'storage_path' => 'sounds/1/a.m4a', 'status' => 'ready']);
        $this->asUser($owner);
        $this->app->mergeConfig(['app' => ['debug' => true]]);
        $deleted = false;

        event()->addListener('app:db.queryExecuted', function (string $query) use ($audio, &$deleted): void {
            if (!$deleted && str_starts_with($query, 'SELECT') && str_contains($query, 'audios')) {
                $deleted = true;
                $audio->update(['status' => 'deleted']);
            }
        });

        $this->getJson('/api/v1/audios/' . $audio->id)->assertStatus(404);
        $this->assertTrue($deleted);
    }

    public function testVideoUpdatesCannotDetachAudioOrChangeRenderedSettings(): void
    {
        $owner = $this->makeUser();
        $audio = Audio::create(['user_id' => $owner->id, 'title' => 'Sound', 'storage_path' => 'sounds/1/a.m4a', 'status' => 'ready']);
        $video = $this->makeVideo($owner, ['audio_id' => $audio->id]);
        $this->asUser($owner);

        foreach ([
            'audio_id' => null,
            'audio_mode' => null,
            'audio_settings' => null,
            'trim_start' => 1,
            'trim_end' => 5,
            'cut_points' => [1],
            'cover_time' => 2,
            'crop_mode' => 'fill',
            'text_overlay' => 'Changed',
            'original_audio_muted' => true,
            'filter_settings' => ['brightness' => 110],
            'effect_settings' => ['visual' => 'Glow']
        ] as $field => $value) {
            $this->patchJson('/api/v1/videos/' . $video->id, [$field => $value])->assertStatus(422)->assertJsonValidationErrors([$field]);
        }

        $this->assertSame($audio->id, $video->refresh()->audio_id);
        foreach (['storage_path', 'user_id', 'source_video_id'] as $field) {
            $this->patchJson('/api/v1/audios/' . $audio->id, [$field => null])->assertStatus(422)->assertJsonValidationErrors([$field]);
        }
        $this->patchJson('/api/v1/videos/' . $video->id, ['caption' => 'Updated', 'visibility' => 'private', 'reuse_content' => false])->assertOk();
    }

    public function testNullNonNullableVideoInputsAreValidationErrors(): void
    {
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner);
        storage('public')->put('videos/1/input.mp4', 'video');
        $this->asUser($owner);

        foreach (['visibility', 'high_quality_upload', 'crop_mode', 'original_audio_muted'] as $field) {
            $this->postJson('/api/v1/videos', [...$this->videoRights(), 'storage_path' => 'videos/1/input.mp4', $field => null])
                ->assertStatus(422)->assertJsonValidationErrors([$field]);
        }
        foreach (['visibility', 'high_quality_upload', 'reuse_content', 'pinned'] as $field) {
            $this->patchJson('/api/v1/videos/' . $video->id, [$field => null])->assertStatus(422)->assertJsonValidationErrors([$field]);
        }
        $this->assertDatabaseCount('videos', 1);
    }

    public function testDeletedSoundCannotTurnPendingOrFailedVideoIntoOriginalAudio(): void
    {
        $owner = $this->makeUser();
        $creator = $this->makeUser('creator');
        $audio = Audio::create(['user_id' => $owner->id, 'title' => 'Sound', 'storage_path' => 'sounds/1/a.m4a', 'status' => 'deleted']);
        $pending = $this->makeVideo($creator, ['audio_id' => $audio->id, 'status' => 'processing']);
        $failed = $this->makeVideo($creator, ['audio_id' => $audio->id, 'status' => 'failed']);
        $published = $this->makeVideo($creator, ['audio_id' => $audio->id]);
        (new DeleteAudio($audio->id))->handle();

        $processor = new class extends MediaProcessor {
            public int $calls = 0;
            public function video(Video $video): array
            {
                $this->calls++;
                return ['storage_path' => $video->storage_path, 'duration' => 1];
            }
        };
        $this->app->instance(MediaProcessor::class, $processor);
        VideoRetry::retry();
        (new ProcessVideo($pending->id))->handle();
        (new ProcessVideo($failed->id))->handle();
        $this->assertSame(0, $processor->calls);
        $this->assertSame('source_unavailable', $pending->refresh()->status);
        $this->assertSame('source_unavailable', $failed->refresh()->status);
        $this->assertSame('published', $published->refresh()->status);
        $original = $this->makeVideo($creator, ['status' => 'processing']);
        $this->assertFalse((new Audio())->remove());
        $this->assertSame('processing', $original->refresh()->status);
    }

    public function testDeletingOriginalVideoDuringEncodingPreventsDerivativePublication(): void
    {
        $owner = $this->makeUser();
        $creator = $this->makeUser('creator');
        $source = $this->makeVideo($owner);
        $audio = Audio::create([
            'user_id' => $owner->id,
            'source_video_id' => $source->id,
            'origin' => 'original',
            'title' => 'Original',
            'storage_path' => 'sounds/1/a.m4a',
            'status' => 'ready',
            'reuse_allowed' => true
        ]);
        $pending = $this->makeVideo($creator, ['audio_id' => $audio->id, 'status' => 'processing']);
        $this->app->instance(
            MediaProcessor::class,
            new class ($source) extends MediaProcessor {
            public function __construct(private Video $source)
            {}
            public function video(Video $video): array
            {
                $this->source->update(['status' => 'deleted']);
                (new DeleteVideo($this->source->id))->handle();
                storage('public')->put('videos/2/encoded.mp4', 'encoded');
                return ['storage_path' => 'public:videos/2/encoded.mp4', 'duration' => 1];
            }
            }
        );
        (new ProcessVideo($pending->id))->handle();
        $this->assertSame('source_unavailable', $pending->refresh()->status);
        $this->assertFalse(storage('public')->exists('videos/2/encoded.mp4'));
    }

    public function testSuspendedAccountsCannotWriteThroughGuestShareAndViewRoutes(): void
    {
        $owner = $this->makeUser();
        $viewer = $this->makeUser('viewer');
        $video = $this->makeVideo($owner);
        $this->asUser($viewer);
        $viewer->update(['status' => 'suspended']);

        $this->postJson('/api/v1/videos/' . $video->id . '/share')->assertStatus(403);
        $this->postJson('/api/v1/videos/' . $video->id . '/view')->assertStatus(403);
        $this->assertDatabaseCount('shares', 0);
        $this->assertDatabaseCount('video_views', 0);
        $this->flushHeaders()->postJson('/api/v1/videos/' . $video->id . '/share')->assertStatus(201);
        $this->postJson('/api/v1/videos/' . $video->id . '/view')->assertStatus(201);
    }

    public function testOldNotificationSnapshotsRespectCurrentModerationAndBlocks(): void
    {
        $owner = $this->makeUser();
        $actor = $this->makeUser('actor');
        $video = $this->makeVideo($owner);
        $this->asUser($actor);
        $commentId = $this->postJson('/api/v1/videos/' . $video->id . '/comments', ['body' => 'Private removed text'])->assertStatus(201)->json('data.id');
        $notice = Notification::query()->first();
        Comment::whereKey($commentId)->update(['moderation_status' => 'restricted']);

        $this->asUser($owner)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.data.excerpt', null);
        $this->patchJson('/api/v1/notifications/' . $notice->id . '/read')->assertOk()->assertJsonPath('data.data.excerpt', null);
        $this->postJson('/api/v1/users/' . $actor->id . '/block')->assertStatus(201);
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.data.actor', null);
        $this->assertSame('Private removed text', $notice->refresh()->data['excerpt']);

        $proof = $this->postJson('/api/v1/auth/reauthenticate', ['purpose' => 'export_data', 'password' => 'password123'])->assertOk()->json('data.proof');
        $id = $this->postJson('/api/v1/me/export-requests', ['proof' => $proof])->assertStatus(202)->json('data.id');
        \App\Services\PrivacyRequests::process($id);
        $contents = \Spark\Facades\Hash::decrypt(storage('local')->get(\App\Services\PrivacyRequests::exportPath($id)));
        $this->assertFalse(str_contains($contents, 'Private removed text'));
    }

    public function testAnExportExpiredDuringProcessingCannotReturnToFailed(): void
    {
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner);
        Comment::create(['user_id' => $owner->id, 'video_id' => $video->id, 'body' => "Invalid UTF-8: \xB1"]);
        $this->asUser($owner);
        $proof = $this->postJson('/api/v1/auth/reauthenticate', ['purpose' => 'export_data', 'password' => 'password123'])->assertOk()->json('data.proof');
        $id = $this->postJson('/api/v1/me/export-requests', ['proof' => $proof])->assertStatus(202)->json('data.id');
        $this->app->mergeConfig(['app' => ['debug' => true]]);
        $interrupted = false;
        event()->addListener('app:db.queryExecuted', function (string $query) use ($id, &$interrupted): void {
            if (!$interrupted && str_starts_with($query, 'SELECT') && str_contains($query, 'notifications')) {
                $interrupted = true;
                \App\Services\PrivacyRequests::removeExport($id);
            }
        });
        $this->assertThrows(\RuntimeException::class, fn() => \App\Services\PrivacyRequests::process($id));
        $this->assertTrue($interrupted);
        $this->assertDatabaseHas('privacy_requests', ['id' => $id, 'status' => 'expired', 'active_key' => null]);
    }

    public function testRegistrationNormalizesEmailBeforeUniquenessAndLogin(): void
    {
        $body = [...$this->enrollment(), 'username' => 'mixed', 'email' => 'Mixed@Example.COM', 'password' => 'password123', 'password_confirmation' => 'password123'];
        $id = $this->postJson('/api/v1/auth/register', $body)->assertStatus(201)->json('data.user.id');
        $this->assertDatabaseHas('users', ['id' => $id, 'email' => 'mixed@example.com']);
        \App\Models\User::whereKey($id)->update(['email_verified_at' => now()]);
        $this->postJson('/api/v1/auth/login', ['email' => 'MIXED@example.com', 'password' => 'password123'])->assertOk();
        $this->postJson('/api/v1/auth/register', [...$body, 'username' => 'duplicate', 'email' => 'MIXED@EXAMPLE.COM'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
        $this->assertDatabaseCount('users', 1);
    }

    public function testPasswordChangeInvalidatesPreviouslyIssuedResetLink(): void
    {
        $owner = $this->makeUser();
        query('password_reset_tokens')->insert(['email' => $owner->email, 'token' => password_hash('old-token', PASSWORD_DEFAULT), 'created_at' => now()]);
        $this->asUser($owner)->putJson('/api/v1/auth/password', [
            'current_password' => 'password123',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $owner->email,
            'token' => 'old-token',
            'password' => 'replacement123',
            'password_confirmation' => 'replacement123',
        ])->assertStatus(422);
    }

    public function testAudioRetryRecoversARealFailedJobAndSkipsIneligibleRecords(): void
    {
        $owner = $this->makeUser();
        $attributes = [
            'user_id' => $owner->id,
            'title' => 'Sound',
            'storage_path' => 'sounds/1/source.m4a',
            'rights_version' => config('policies.rights_version'),
            'rights_confirmed_at' => now()
        ];
        $audio = Audio::create($attributes);
        $blocked = Audio::create([...$attributes, 'status' => 'failed', 'moderation_status' => 'restricted']);
        $deleted = Audio::create([...$attributes, 'status' => 'deleted']);
        $this->app->instance(MediaProcessor::class, new class extends MediaProcessor {
            public function audio(Audio $audio): array
            {
                throw new \RuntimeException('Temporary encoder failure.');
            }
        });
        \App\Jobs\ProcessAudio::dispatch($audio->id)->send();
        $queue = app(\Spark\Queue\Queue::class);
        $queue->work(once: true, timeout: 5, sleep: 0);
        $this->assertSame('failed', $audio->refresh()->status);
        $this->assertCount(1, $queue->getFailedJobs());

        $this->app->instance(MediaProcessor::class, new class extends MediaProcessor {
            public function audio(Audio $audio): array
            {
                return ['storage_path' => $audio->storage_path, 'duration' => 3];
            }
        });
        $this->assertTrue(\App\Services\AudioRetry::retry());
        $this->assertCount(1, $queue->getJobs(status: 'pending'));
        $queue->work(once: true, timeout: 5, sleep: 0);
        $this->assertSame('ready', $audio->refresh()->status);
        $this->assertSame('failed', $blocked->refresh()->status);
        $this->assertSame('deleted', $deleted->refresh()->status);
        $this->assertFalse(\App\Services\AudioRetry::retry($audio->id));
    }

    public function testOneBrokenMediaCleanupDoesNotStopOtherCleanupAndRetention(): void
    {
        $owner = $this->makeUser();
        $broken = Audio::create(['user_id' => $owner->id, 'title' => 'Broken', 'storage_path' => 'sounds/1/broken.m4a', 'status' => 'deleted']);
        $healthy = Audio::create(['user_id' => $owner->id, 'title' => 'Healthy', 'storage_path' => 'sounds/1/healthy.m4a', 'status' => 'deleted']);
        mkdir(storage('public')->path('sounds/1/broken.m4a'), 0700, true);
        storage('public')->put('sounds/1/healthy.m4a', 'audio');
        query('audit_events')->insert(['actor_type' => 'system', 'action' => 'old', 'context' => '{}', 'created_at' => now()->subDays(366)->toDateTimeString()]);
        (new \App\Jobs\FoundationMaintenance())->handle();
        $this->assertTrue(Audio::whereKey($broken->id)->exists());
        $this->assertSame(null, Audio::find($healthy->id));
        $this->assertFalse(storage('public')->exists('sounds/1/healthy.m4a'));
        $this->assertDatabaseCount('audit_events', 0);
        rmdir(storage('public')->path('sounds/1/broken.m4a'));
        (new \App\Jobs\FoundationMaintenance())->handle();
        $this->assertSame(null, Audio::find($broken->id));
    }

    public function testAudioRetryCommandHonorsIdAndRejectsInvalidInput(): void
    {
        $this->app->instance(\Spark\Console\Commands::class, new \Spark\Console\Commands());
        require dirname(__DIR__, 2) . '/routes/console.php';
        $owner = $this->makeUser();
        $attributes = [
            'user_id' => $owner->id,
            'title' => 'Sound',
            'storage_path' => 'sounds/1/source.m4a',
            'rights_confirmed_at' => now(),
            'status' => 'failed'
        ];
        $audio = Audio::create($attributes);
        $other = Audio::create($attributes);
        $console = new \Spark\Console\Console(command());
        foreach (['', '0', '-1', 'invalid', true] as $id) {
            $this->assertThrows(\InvalidArgumentException::class, fn() => $console->executeCommand('audios:retry', ['id' => $id]));
        }
        $console->executeCommand('audios:retry', ['id' => (string) $audio->id]);
        $this->assertSame('processing', $audio->refresh()->status);
        $this->assertSame('failed', $other->refresh()->status);
        $this->assertDatabaseCount('jobs', 1);
    }
}
