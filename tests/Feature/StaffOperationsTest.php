<?php

namespace Tests\Feature;

use App\Services\Staff\{Operations, Sessions};
use Spark\Facades\DB;
use Tests\Support\{CommunicationFixtures, StaffBrowser};
use Tests\TestCase;

class StaffOperationsTest extends TestCase
{
    use CommunicationFixtures, StaffBrowser;

    public function testOperationsPagesHaveSafePropsAndDriverAwareUnknownWorkerStates(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner, ['status' => 'failed', 'processing_state' => 'failed']);
        $this->makeVideo($owner, ['status' => 'deleted', 'processing_state' => 'failed']);
        $this->browserLogin($this->makeUser('operator'), 'operations');
        foreach (['/operations/overview', '/operations/jobs', '/operations/media', '/operations/media?kind=audio', '/operations/communications', '/operations/audit', '/releases', '/releases/' . $video->id] as $path) {
            $page = $this->browserPage($path)->assertOk();
            $this->assertFalse(str_contains($page->content(), $video->storage_path));
            $this->assertFalse(str_contains($page->content(), 'serialized'));
        }
        $this->browserPage('/operations/overview')->assertJsonPath('props.health.workers.0.status', 'unknown')->assertJsonPath('props.health.queue_availability', 'available');
        $this->browserPage('/operations/overview')->assertJsonPath('props.health.domains.0.count', 1);
        $this->app->mergeConfig(['queue' => ['default' => 'file']]);
        $this->browserPage('/operations/jobs')->assertOk()->assertJsonPath('props.health.queue_availability', 'unavailable')->assertJsonCount(0, 'props.health.queues');
        $this->browserPage('/billing/invoices')->assertForbidden();
    }

    public function testMediaRetryRemainsRunningUntilEncodingHasARecordedOutcome(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner, ['status' => 'failed', 'processing_state' => 'failed', 'publication_intent' => 'draft']);
        $this->browserLogin($this->makeUser('operator'), 'operations');
        $input = ['reason' => 'Encoding dependency repaired', 'idempotency_key' => 'video-recovery-first'];
        $this->browserWrite('/operations/media/videos/' . $video->id . '/retry', $input)->assertStatus(303);
        $id = (int) DB::table('staff_operations')->value('id');
        Operations::run($id);
        $this->assertDatabaseHas('staff_operations', ['id' => $id, 'status' => 'running', 'result_code' => 'awaiting_media']);
        $this->assertDatabaseHas('videos', ['id' => $video->id, 'publication_intent' => 'draft']);
        Operations::run($id);
        $this->assertDatabaseHas('staff_operations', ['id' => $id, 'status' => 'running']);
        DB::table('videos')->where('id', $video->id)->update(['processing_state' => 'ready', 'status' => 'draft']);
        Operations::run($id);
        $this->assertDatabaseHas('staff_operations', ['id' => $id, 'status' => 'completed', 'result_code' => 'media_ready']);
        $this->assertBrowserRejected($this->browserWrite('/operations/media/videos/' . $video->id . '/retry', [...$input, 'idempotency_key' => 'video-recovery-second']));
    }

    public function testReleaseRecoveryNeverPublishesEarlyOrReschedulesAPausedDraft(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner, ['status' => 'scheduled', 'processing_state' => 'ready', 'publication_state' => 'scheduled',
            'publish_at' => Sessions::timestamp(3600), 'publication_intent' => 'scheduled']);
        $this->browserLogin($this->makeUser('operator'), 'operations');
        $input = ['reason' => 'Investigate publication delay', 'idempotency_key' => 'release-recovery-first'];
        $this->assertBrowserRejected($this->browserWrite('/releases/' . $video->id . '/recover', $input));
        $this->assertDatabaseCount('staff_operations', 0);
        DB::table('videos')->where('id', $video->id)->update(['publish_at' => Sessions::timestamp(-60)]);
        $this->browserWrite('/releases/' . $video->id . '/recover', $input)->assertStatus(303);
        Operations::run((int) DB::table('staff_operations')->value('id'));
        $this->assertDatabaseHas('videos', ['id' => $video->id, 'publication_state' => 'draft', 'release_error' => 'scheduling_access_ended']);
        $this->assertDatabaseHas('staff_operations', ['status' => 'no_op', 'result_code' => 'release_requires_owner_review']);
    }

    public function testInterruptedMediaDispatchResumesWithoutFinishingOrDispatchingTwice(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner, ['status' => 'failed', 'processing_state' => 'failed', 'publication_intent' => 'draft']);
        $audio = \App\Models\Audio::create([
            'user_id' => $owner->id, 'title' => 'Uploaded audio', 'origin' => 'upload', 'status' => 'failed',
            'storage_path' => 'audios/fixture.mp3', 'rights_confirmed_at' => now(), 'rights_version' => config('policies.rights_version'),
        ]);
        $this->browserLogin($this->makeUser('operator'), 'operations');
        foreach (['videos' => $video->id, 'audios' => $audio->id] as $kind => $target) {
            $this->browserWrite('/operations/media/' . $kind . '/' . $target . '/retry', [
                'reason' => 'Resume interrupted media recovery', 'idempotency_key' => 'interrupted-' . $kind . '-dispatch',
            ])->assertStatus(303);
            $id = (int) DB::table('staff_operations')->orderBy('id', 'desc')->value('id');
            Operations::run($id);
            $jobs = DB::table('jobs')->count();
            // Simulate process loss between dispatch and saving the awaiting result.
            DB::table('staff_operations')->where('id', $id)->update(['result_code' => null]);
            Operations::run($id);
            $this->assertDatabaseHas('staff_operations', ['id' => $id, 'status' => 'running', 'result_code' => 'awaiting_media']);
            $this->assertDatabaseCount('jobs', $jobs);
            DB::table($kind)->where('id', $target)->update($kind === 'videos' ? ['status' => 'draft', 'processing_state' => 'ready'] : ['status' => 'ready']);
            DB::table('staff_operations')->where('id', $id)->update(['result_code' => null]);
            Operations::run($id);
            $this->assertDatabaseHas('staff_operations', ['id' => $id, 'status' => 'completed', 'result_code' => 'media_ready']);
        }
    }

    public function testAwaitingMediaStopsWhenItsOwnerRightsOrContentBecomeUnavailable(): void
    {
        $this->browserLogin($this->makeUser('operator'), 'operations');
        foreach (['deleted', 'restricted', 'rights', 'owner'] as $condition) {
            $owner = $this->makeUser('owner_' . $condition);
            $video = $this->makeVideo($owner, ['status' => 'failed', 'processing_state' => 'failed']);
            $this->browserWrite('/operations/media/videos/' . $video->id . '/retry', [
                'reason' => 'Review media processing eligibility', 'idempotency_key' => 'media-eligibility-' . $condition,
            ])->assertStatus(303);
            $id = (int) DB::table('staff_operations')->orderBy('id', 'desc')->value('id');
            Operations::run($id);
            $this->assertDatabaseHas('staff_operations', ['id' => $id, 'status' => 'running']);
            if ($condition === 'owner') {
                DB::table('users')->where('id', $owner->id)->update(['status' => 'suspended']);
            } else {
                DB::table('videos')->where('id', $video->id)->update(match ($condition) {
                    'deleted' => ['status' => 'deleted', 'processing_state' => 'ready'],
                    'restricted' => ['moderation_status' => 'restricted'],
                    'rights' => ['rights_confirmed_at' => null],
                });
            }
            Operations::run($id);
            $this->assertDatabaseHas('staff_operations', ['id' => $id, 'status' => 'no_op', 'result_code' => 'media_not_retryable']);
        }
    }

    public function testAudioDispatchFailureLeavesMediaRetryable(): void
    {
        $owner = $this->makeUser('owner');
        $audio = \App\Models\Audio::create([
            'user_id' => $owner->id, 'title' => 'Uploaded audio', 'origin' => 'upload', 'status' => 'failed',
            'storage_path' => 'audios/fixture.mp3', 'rights_confirmed_at' => now(), 'rights_version' => config('policies.rights_version'),
        ]);
        $this->browserLogin($this->makeUser('operator'), 'operations');
        $input = ['reason' => 'Retry after queue transport recovery', 'idempotency_key' => 'audio-dispatch-failure'];
        $this->browserWrite('/operations/media/audios/' . $audio->id . '/retry', $input)->assertStatus(303);
        $this->app->mergeConfig(['queue' => ['default' => 'unavailable']]);
        Operations::run((int) DB::table('staff_operations')->value('id'));
        $this->assertDatabaseHas('staff_operations', ['status' => 'failed', 'result_code' => 'recovery_failed']);
        $this->assertDatabaseHas('audios', ['id' => $audio->id, 'status' => 'failed']);
        $this->app->mergeConfig(['queue' => ['default' => 'database']]);
        $this->browserWrite('/operations/media/audios/' . $audio->id . '/retry', [...$input, 'idempotency_key' => 'audio-dispatch-restored'])->assertStatus(303);
        Operations::run((int) DB::table('staff_operations')->orderBy('id', 'desc')->value('id'));
        $this->assertDatabaseHas('audios', ['id' => $audio->id, 'status' => 'processing']);
        $this->assertDatabaseHas('staff_operations', ['status' => 'running', 'result_code' => 'awaiting_media']);
    }

    public function testMaintenanceRotatesAFullBatchOfBusyOperations(): void
    {
        $this->browserLogin($actor = $this->makeUser('operator'), 'operations');
        $locks = [];
        try {
            for ($index = 0; $index < 21; $index++) {
                $id = (int) DB::table('staff_operations')->insert([
                    'actor_id' => $actor->id, 'membership_version' => 1, 'environment' => config('billing.environment'),
                    'type' => 'realtime.recover', 'target_id' => 0, 'status' => 'queued',
                    'idempotency_hash' => hash('sha256', 'busy-' . $index), 'request_hash' => hash('sha256', 'input-' . $index),
                    'created_at' => Sessions::timestamp(-120), 'updated_at' => Sessions::timestamp(-120), 'expires_at' => Sessions::timestamp(3600),
                ]);
                if ($index < 20) {
                    $lock = \Spark\Facades\Cache::lock('staff.operation.' . $id, 60);
                    $this->assertTrue($lock->acquire());
                    $locks[] = $lock;
                }
            }
            (new \App\Jobs\StaffMaintenance())->handle();
            $this->assertDatabaseHas('staff_operations', ['id' => $id, 'status' => 'queued']);
            (new \App\Jobs\StaffMaintenance())->handle();
            $this->assertDatabaseHas('staff_operations', ['id' => $id, 'status' => 'no_op', 'result_code' => 'outbox_empty']);
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }

    public function testAuditScopeOmitsOtherDomainsAndSensitiveContext(): void
    {
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        \App\Services\AuditTrail::record('billing.secret_test', ['email' => 'secret@example.test'], null, 'system');
        \App\Services\AuditTrail::record('moderation.decided', ['report_id' => 99, 'reason' => 'Private evidence secret'], null, 'system');
        $page = $this->browserPage('/operations/audit')->assertOk();
        $this->assertTrue(str_contains($page->content(), 'moderation.decided'));
        foreach (['secret@example.test', 'billing.secret_test', 'Private evidence secret'] as $secret) {
            $this->assertFalse(str_contains($page->content(), $secret));
        }
    }

    public function testQueuedMediaRechecksModerationAndAnnouncementRecoveryHonorsRevokedConsent(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $fan = $this->subscriber($artist->id);
        $announcement = $this->campaign($owner);
        $video = $this->makeVideo($owner, ['status' => 'failed', 'processing_state' => 'failed']);
        $this->browserLogin($this->makeUser('operator'), 'operations');
        $accepted = DB::table('announcements')->where('id', $announcement->id)->value('accepted_at');
        DB::table('announcements')->where('id', $announcement->id)->update(['created_at' => Sessions::timestamp(-30 * 86400)]);
        $this->browserPage('/operations/communications')->assertOk()->assertJsonPath('props.delivery.pending', 1)
            ->assertJsonPath('props.delivery.oldest_at', \App\Services\Billing\Billing::date($accepted));
        $input = ['reason' => 'Repair completed and recovery reviewed', 'idempotency_key' => 'consent-safe-recovery'];
        $this->browserWrite('/operations/communications/' . $announcement->id . '/retry', $input)->assertStatus(303);
        $this->browserWrite('/operations/media/videos/' . $video->id . '/retry', $input)->assertStatus(303);
        \App\Services\Communications::subscribe($fan, $artist->id, false, config('communications.consent_version'));
        DB::table('videos')->where('id', $video->id)->update(['moderation_status' => 'restricted']);
        foreach (DB::table('staff_operations')->pluck('id') as $id) {
            Operations::run((int) $id);
        }
        $this->assertDatabaseHas('announcement_deliveries', ['announcement_id' => $announcement->id, 'user_id' => $fan->id, 'status' => 'suppressed']);
        $this->assertDatabaseHas('staff_operations', ['type' => 'communications.retry', 'status' => 'completed']);
        $this->assertDatabaseHas('staff_operations', ['type' => 'media.video', 'status' => 'no_op']);
        $this->assertDatabaseHas('videos', ['id' => $video->id, 'processing_state' => 'failed']);
        $this->assertFalse(\App\Services\VideoRetry::retry($video->id));
        $page = $this->browserPage('/operations/communications')->assertOk();
        $page->assertJsonPath('props.delivery.pending', 0)->assertJsonPath('props.delivery.oldest_at', null);
        $this->assertFalse(str_contains($page->content(), 'Listen to my latest original song'));
        $this->assertFalse(str_contains($page->content(), $fan->email));
    }

    public function testNewMutationFamiliesRequireCsrfAndDomainPermission(): void
    {
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        foreach ([
            ['POST', '/billing/webhooks/1/retry'], ['POST', '/billing/subscriptions/1/reconcile'],
            ['POST', '/billing/checkouts/1/recover'], ['POST', '/billing/adjustments/1/allocation'],
            ['POST', '/operations/media/videos/1/retry'], ['POST', '/operations/media/audios/1/retry'],
            ['POST', '/operations/communications/1/retry'], ['POST', '/operations/realtime/recover'],
            ['POST', '/releases/1/recover'], ['POST', '/exports'], ['PATCH', '/settings/general'],
            ['POST', '/settings/taxonomy/genres'], ['PATCH', '/settings/taxonomy/genres/1'],
            ['PATCH', '/settings/team/1'],
        ] as [$method, $path]) {
            $this->request($method, $path, [], $this->browserHeaders())->assertStatus(419);
            $this->browserWrite($path, [], $method)->assertForbidden();
        }
    }
}
