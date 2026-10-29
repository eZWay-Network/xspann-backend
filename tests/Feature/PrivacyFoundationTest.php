<?php

namespace Tests\Feature;

use App\Jobs\{FoundationMaintenance, ProcessPrivacyRequest, SendDeletionConfirmation};
use App\Models\{Comment, Like, Notification, User, Video};
use App\Services\{AccountSecurity, Moderation, PendingUploads, PrivacyRequests};
use Spark\Facades\{Hash, Queue};
use Tests\TestCase;

class PrivacyFoundationTest extends TestCase
{
    public function testDeletionFailureRetainsDurableStateAndCanResumeStorageCleanup(): void
    {
        $server = new \Tests\Support\HttpServer(dirname(__DIR__) . '/Fixtures/s3-router.php', $this->storagePath, ['XSPANN_S3_TEST_ROOT' => $this->storagePath]);
        try {
            $this->useS3(['endpoint' => $server->url, 'use_path_style_endpoint' => true]);
            $owner = $this->makeUser();
            $owner->update(['avatar' => 'avatars/1/avatar.png']);
            storage('s3')->put('avatars/1/avatar.png', 'avatar');
            $this->asUser($owner);
            $this->postJson('/api/v1/me/deletion-requests', ['proof' => $this->proof('delete_account')])->assertStatus(202);
            $record = query('privacy_requests')->where('kind', 'deletion')->first();
            file_put_contents($this->storagePath . '/fail-delete', '');
            $this->assertThrows(\RuntimeException::class, fn() => (new ProcessPrivacyRequest($record->id))->handle());
            $this->assertDatabaseHas('privacy_requests', ['id' => $record->id, 'status' => 'failed', 'attempts' => 1]);
            $this->assertDatabaseHas('users', ['id' => $owner->id, 'status' => 'deleting']);
            unlink($this->storagePath . '/fail-delete');
            (new ProcessPrivacyRequest($record->id))->handle();
            $this->assertSame(null, User::find($owner->id));
            $this->assertFalse(storage('s3')->exists('avatars/1/avatar.png'));
            $this->assertDatabaseHas('privacy_requests', ['id' => $record->id, 'status' => 'completed', 'attempts' => 2]);
        } finally {
            $server->stop();
        }
    }

    public function testDeletionEmailWorkerSendsAnOwnerConfirmationAndSuppressesRepeats(): void
    {
        $mail = new class extends \Spark\Utils\Mail {
            public int $calls = 0;
            public function send(): bool
            {
                $this->calls++;
                return $this->preSend();
            }
        };
        $this->app->instance(\Spark\Utils\Mail::class, $mail);
        $owner = $this->makeUser();
        (new SendDeletionConfirmation(Hash::encrypt($owner->email)))->handle();
        (new SendDeletionConfirmation(Hash::encrypt($owner->email)))->handle();
        (new SendDeletionConfirmation(Hash::encrypt('absent@example.com')))->handle();
        $this->assertSame(1, $mail->calls);
        preg_match('/delete-account\?token=([a-f0-9]{64})/', $mail->Body, $matches);
        $this->assertTrue(isset($matches[1]));
        $this->assertDatabaseCount('privacy_requests', 0);
        $this->postJson('/api/v1/account/deletion-confirmations', ['token' => $matches[1]])->assertStatus(202);
        $this->assertSame('deleting', $owner->refresh()->status);
    }

    public function testFailedExportArtifactsHaveABoundedRetentionToo(): void
    {
        $owner = $this->makeUser();
        $this->asUser($owner);
        $id = $this->postJson('/api/v1/me/export-requests', ['proof' => $this->proof('export_data')])->assertStatus(202)->json('data.id');
        storage('local')->put(PrivacyRequests::exportPath($id), Hash::encrypt('partial private export'));
        query('privacy_requests')->where('id', $id)->update(['status' => 'failed', 'attempts' => 5, 'expires_at' => now()->subSeconds(1)->toDateTimeString()]);
        (new FoundationMaintenance())->handle();
        $this->assertFalse(storage('local')->exists(PrivacyRequests::exportPath($id)));
        $this->assertDatabaseHas('privacy_requests', ['id' => $id, 'status' => 'expired', 'active_key' => null]);
    }

    private function proof(string $purpose): string
    {
        return $this->postJson('/api/v1/auth/reauthenticate', ['purpose' => $purpose, 'password' => 'password123'])->assertOk()->json('data.proof');
    }

    public function testExportIsPrivateOwnerBoundExpiringAndContainsNoCredentials(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser('other');
        $video = $this->makeVideo($owner);
        $token = $this->token($owner);
        $this->withToken($token);
        $proof = $this->proof('export_data');
        $id = $this->postJson('/api/v1/me/export-requests', ['proof' => $proof])->assertStatus(202)->json('data.id');
        $this->postJson('/api/v1/me/export-requests', ['proof' => $proof])->assertStatus(422);
        $second = $this->proof('export_data');
        $this->postJson('/api/v1/me/export-requests', ['proof' => $second])->assertStatus(202)->assertJsonPath('data.id', $id);
        (new ProcessPrivacyRequest($id))->handle();
        (new ProcessPrivacyRequest($id))->handle();
        $url = $this->getJson('/api/v1/me/export-requests/' . $id)->assertOk()->assertJsonPath('data.status', 'ready')->json('data.download_url');
        $this->assertFalse(str_contains(storage('local')->get(PrivacyRequests::exportPath($id)), $owner->email));
        $this->assertFalse(storage('public')->exists(PrivacyRequests::exportPath($id)));
        $url = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
        $download = $this->getJson($url)->assertOk()->assertHeader('Cache-Control', 'no-store');
        $this->assertSame($owner->email, $download->json('account.email'));
        $this->assertSame('1990-01-01', $download->json('date_of_birth'));
        foreach (['password', 'token_hash', 'mfa_secret', $other->email] as $secret) {
            $this->assertFalse(str_contains($download->content(), $secret));
        }
        $this->asUser($other)->getJson('/api/v1/me/export-requests/' . $id)->assertStatus(404);
        $this->getJson($url)->assertStatus(404);
        $this->flushHeaders()->getJson($url)->assertStatus(401);
        $this->withToken($token)->getJson($url . 'tampered')->assertStatus(422);
        query('privacy_requests')->where('id', $id)->update(['expires_at' => now()->subSeconds(1)->toDateTimeString()]);
        $this->getJson('/api/v1/me/export-requests/' . $id)->assertOk()->assertJsonPath('data.status', 'expired');
        $this->assertFalse(storage('local')->exists(PrivacyRequests::exportPath($id)));
    }

    public function testReauthenticationProofCannotCrossSessionPurposeOwnerOrExpiry(): void
    {
        $owner = $this->makeUser();
        $first = $this->token($owner);
        $this->withToken($first);
        $proof = $this->proof('export_data');
        $this->postJson('/api/v1/me/deletion-requests', ['proof' => $proof])->assertStatus(422);
        $this->asUser($owner)->postJson('/api/v1/me/export-requests', ['proof' => $proof])->assertStatus(422);
        $this->asUser($this->makeUser('other'))->postJson('/api/v1/me/export-requests', ['proof' => $proof])->assertStatus(422);
        query('account_action_tokens')->where('token_hash', hash('sha256', $proof))->update(['expires_at' => now()->subSeconds(1)->toDateTimeString()]);
        $this->withToken($first)->postJson('/api/v1/me/export-requests', ['proof' => $proof])->assertStatus(422);
        $this->assertDatabaseCount('privacy_requests', 0);
    }

    public function testDeletionRevokesImmediatelyCleansMediaAndCascadesWithoutLosingReportedEvidence(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser('other');
        $video = $this->makeVideo($owner);
        storage('public')->put($this->mediaKey($video->storage_path), 'video');
        storage('public')->put('avatars/1/avatar.png', 'avatar');
        storage('public')->put('sounds/1/abandoned.m4a', 'unused');
        PendingUploads::track($owner->id, 'sounds/1/abandoned.m4a');
        $owner->update(['avatar' => 'avatars/1/avatar.png']);
        $report = Moderation::report($other, ['video_id' => $video->id, 'reason' => 'Review']);
        $otherVideo = $this->makeVideo($other, ['likes_count' => 1, 'comments_count' => 2]);
        Like::create(['user_id' => $owner->id, 'video_id' => $otherVideo->id]);
        $parent = Comment::create(['user_id' => $owner->id, 'video_id' => $otherVideo->id, 'body' => 'My data']);
        Comment::create(['user_id' => $other->id, 'video_id' => $otherVideo->id, 'parent_id' => $parent->id, 'body' => 'Reply']);
        Notification::create(['user_id' => $other->id, 'type' => 'video_liked', 'data' => ['actor' => ['id' => $owner->id, 'name' => $owner->name]]]);
        $this->asUser($owner);
        $proof = $this->proof('delete_account');
        $this->postJson('/api/v1/me/deletion-requests', ['proof' => $proof])->assertStatus(202);
        $this->assertSame('deleting', $owner->refresh()->status);
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->flushHeaders()->getJson('/api/v1/videos/' . $video->id)->assertStatus(404);
        $record = query('privacy_requests')->where('kind', 'deletion')->first();
        (new ProcessPrivacyRequest($record->id))->handle();
        (new ProcessPrivacyRequest($record->id))->handle();
        $this->assertSame(null, User::find($owner->id));
        foreach ([$this->mediaKey($video->storage_path), 'avatars/1/avatar.png', 'sounds/1/abandoned.m4a'] as $path) {
            $this->assertFalse(storage('public')->exists($path));
        }
        $this->assertDatabaseCount('pending_uploads', 1);
        // A signed upload issued before deletion may still arrive after initial cleanup.
        storage('public')->put('sounds/1/abandoned.m4a', 'late upload');
        query('pending_uploads')->where('user_id', $owner->id)->update(['created_at' => now()->subHours(49)->toDateTimeString()]);
        (new \App\Jobs\PruneUploads())->handle();
        $this->assertFalse(storage('public')->exists('sounds/1/abandoned.m4a'));
        $this->assertDatabaseCount('pending_uploads', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseHas('privacy_requests', ['id' => $record->id, 'status' => 'completed', 'user_id' => null]);
        $this->assertSame(0, $otherVideo->refresh()->likes_count);
        $this->assertSame(0, $otherVideo->comments_count);
        $this->assertSame(null, $report->refresh()->subject_user_id);
        $this->assertTrue(str_contains($report->evidence, 'Hello #world'));
        $this->assertTrue(User::whereKey($other->id)->exists());
    }

    public function testPublicRecoveryIsGenericAndRequiresSingleUseEmailConfirmation(): void
    {
        $user = $this->makeUser();
        $user->update(['status' => 'suspended']);
        $known = $this->postJson('/api/v1/account/deletion-requests', ['email' => $user->email])->assertStatus(202)->json();
        $unknown = $this->postJson('/api/v1/account/deletion-requests', ['email' => 'unknown@example.com'])->assertStatus(202)->json();
        $this->assertSame($known, $unknown);
        $jobs = Queue::getJobs(queue: 'privacy');
        $this->assertCount(2, $jobs);
        $this->assertSame(SendDeletionConfirmation::class, $jobs[0]->getCallback());
        $this->assertFalse(str_contains($jobs[0]->getParameters()[0], $user->email));
        $this->assertDatabaseCount('privacy_requests', 0);
        $this->postJson('/api/v1/account/deletion-confirmations', ['token' => 'invalid'])->assertStatus(422);
        $token = AccountSecurity::issue($user->id, 'email_deletion', 3600);
        $this->postJson('/api/v1/account/deletion-confirmations', ['token' => $token])->assertStatus(202);
        $this->postJson('/api/v1/account/deletion-confirmations', ['token' => $token])->assertStatus(422);
        $this->assertSame('deleting', $user->refresh()->status);
    }

    public function testMaintenanceRecoversPendingJobsAndExpiresPrivateArtifactsAndTokens(): void
    {
        $user = $this->makeUser();
        $this->asUser($user);
        $id = $this->postJson('/api/v1/me/export-requests', ['proof' => $this->proof('export_data')])->assertStatus(202)->json('data.id');
        query('jobs')->where('id', '>', 0)->delete();
        query('privacy_requests')->where('id', $id)->update(['updated_at' => now()->subMinutes(6)->toDateTimeString()]);
        AccountSecurity::issue($user->id, 'onboarding', -1);
        (new FoundationMaintenance())->handle();
        $this->assertCount(1, Queue::getJobs(queue: 'privacy'));
        $this->assertDatabaseCount('account_action_tokens', 0);
        (new FoundationMaintenance())->handle();
        $this->assertCount(1, Queue::getJobs(queue: 'privacy'));
    }
}
