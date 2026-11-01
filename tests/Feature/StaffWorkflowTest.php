<?php

namespace Tests\Feature;

use App\Models\Report;
use App\Services\{Moderation, PrivacyRequests};
use App\Services\Staff\Operations;
use Spark\Facades\{DB, Hash};
use Tests\Support\StaffBrowser;
use Tests\TestCase;

class StaffWorkflowTest extends TestCase
{
    use StaffBrowser;

    public function testSupportCannotFetchEvidenceAndContactRevealIsPurposeBoundAndAudited(): void
    {
        $owner = $this->makeUser('owner');
        $reporter = $this->makeUser('reporter');
        $video = $this->makeVideo($owner);
        $report = Moderation::report($reporter, ['video_id' => $video->id, 'reason' => 'Review this content', 'details' => 'Secret reported context']);
        $support = $this->makeUser('support');
        $this->browserLogin($support, 'support');
        $page = $this->browserPage('/moderation/reports/' . $report->id)->assertOk();
        $this->assertFalse(str_contains($page->content(), 'Secret reported context'));
        $page->assertJsonPath('props.actions.evidence', null)->assertJsonPath('props.actions.decision', null);
        $this->browserEvidence('/moderation/reports/' . $report->id)->assertForbidden();
        $this->browserPage('/users/' . $owner->id)->assertOk();
        $this->post('/users/' . $owner->id . '/contact-reveal', ['reason' => 'Customer requested account support'], ['Accept' => 'application/json'])->assertStatus(419);
        $this->post('/users/' . $owner->id . '/contact-reveal', ['reason' => 'Customer requested account support'], ['Accept' => 'application/json', 'X-CSRF-TOKEN' => session('csrf_token')])
            ->assertOk()->assertJsonPath('email', $owner->email)->assertHeader('Cache-Control', 'no-store, private, max-age=0');
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.contact_revealed', 'actor_id' => $support->id]);
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        $this->browserWrite('/users/' . $owner->id . '/contact-reveal', ['reason' => 'Not permitted'])->assertForbidden();
    }

    public function testPrivateTargetsCannotEnterStaffIntakeAndEvidenceNeverContainsPaths(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner, ['visibility' => 'private']);
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        $this->browserWrite('/moderation/cases', ['target_type' => 'video', 'target_id' => $video->id, 'reason' => 'Guessed private video'])->assertNotFound();
        $this->browserWrite('/moderation/cases', ['target_type' => 'message', 'target_id' => 1, 'reason' => 'Guessed private message'])->assertStatus(303);
        $this->assertDatabaseCount('reports', 0);
        $report = Report::create(['user_id' => $owner->id, 'subject_user_id' => $owner->id, 'video_id' => $video->id,
            'target_type' => 'video', 'target_id' => $video->id, 'reason' => 'Existing reported snapshot', 'evidence' => json_encode(['caption' => 'Retained caption', 'storage_path' => $video->storage_path]), 'status' => 'open', 'version' => 1]);
        $response = $this->browserEvidence('/moderation/reports/' . $report->id)->assertOk()->assertJsonPath('snapshot.caption', 'Retained caption');
        $this->assertFalse(str_contains($response->content(), $video->storage_path));
        $this->get('/moderation/reports/' . $report->id . '/media/arbitrary')->assertForbidden();
        $this->browserPage('/users/not-an-id')->assertNotFound();
        $this->browserPage('/users/999999')->assertNotFound();
        $this->browserPage('/content/videos/' . $video->id)->assertOk()->assertJsonPath('props.case_target', null);
        $this->browserPage('/users/9007199254740993')->assertNotFound();
    }

    public function testPrivacyRetryIsDurableIdempotentAndReauthorizesTheActor(): void
    {
        $owner = $this->makeUser('owner');
        $staff = $this->makeUser('support');
        $request = $this->failedPrivacy($owner->id);
        $this->browserLogin($staff, 'support');
        $page = $this->browserPage('/operations/privacy/' . $request)->assertOk();
        $this->assertFalse(str_contains($page->content(), 'secret-manifest'));
        $input = ['reason' => 'Storage repaired and verified', 'idempotency_key' => 'privacy-operation-one'];
        $this->browserWrite('/operations/privacy/' . $request . '/retry', $input)->assertStatus(303);
        $this->browserWrite('/operations/privacy/' . $request . '/retry', $input)->assertStatus(303);
        $this->assertDatabaseCount('staff_operations', 1);
        $operation = DB::table('staff_operations')->first();
        $this->assertSame('queued', $operation->status);
        $this->browserPage('/operations/actions/' . $operation->id)->assertOk()->assertJsonPath('props.status', 'queued');
        DB::table('staff_accounts')->where('user_id', $staff->id)->update(['version' => 2]);
        Operations::run($operation->id);
        $this->assertDatabaseHas('staff_operations', ['id' => $operation->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('privacy_requests', ['id' => $request, 'status' => 'failed', 'attempts' => 5]);
    }

    public function testPrivacyWorkerCompletesOwnerExportWithoutExposingItsBody(): void
    {
        $owner = $this->makeUser('owner');
        $request = $this->failedPrivacy($owner->id);
        $this->browserLogin($staff = $this->makeUser('support'), 'support');
        $this->browserWrite('/operations/privacy/' . $request . '/retry', ['reason' => 'Underlying issue corrected', 'idempotency_key' => 'privacy-operation-two'])->assertStatus(303);
        $operation = DB::table('staff_operations')->first();
        DB::table('jobs')->where('queue', 'staff')->delete();
        (new \App\Jobs\StaffMaintenance())->handle();
        $this->assertTrue(DB::table('staff_worker_heartbeats')->where('worker', 'staff')->whereNotNull('succeeded_at')->exists());
        $this->assertDatabaseHas('staff_operations', ['id' => $operation->id, 'status' => 'completed']);
        $this->assertDatabaseHas('privacy_requests', ['id' => $request, 'status' => 'ready']);
        $this->assertDatabaseHas('audit_events', ['action' => 'privacy.retry_requested', 'actor_id' => $staff->id, 'actor_type' => 'staff']);
        $page = $this->browserPage('/operations/actions/' . $operation->id)->assertOk();
        $this->assertFalse(str_contains($page->content(), $owner->email));
        $this->assertFalse(str_contains($page->content(), 'manifest'));
        $this->get('/operations/privacy/' . $request . '/download')->assertNotFound();
        (new \App\Jobs\ProcessStaffOperation($operation->id))->handle();
        $this->assertSame(1, (int) DB::table('privacy_requests')->where('id', $request)->value('attempts'));
    }

    public function testEveryReplacementRetiresItsLegacyRouteAndRequiresBrowserCsrf(): void
    {
        $fixture = json_decode(file_get_contents(dirname(__DIR__) . '/Fixtures/staff/api-route-baseline.json'), true);
        $this->browserLogin($this->makeUser('admin'));
        foreach ($fixture['step_2_migration'] as $route) {
            [$method, $path] = explode(' ', $route['legacy'], 2);
            $path = preg_replace('/\{[^}]+\}/', '1', $path);
            $this->request($method, $path, [], ['Accept' => 'application/json'])->assertNotFound();
            if ($method !== 'GET') {
                [, $replacement] = explode(' ', $route['browser'], 2);
                $replacement = preg_replace('/\{[^}]+\}/', '1', $replacement);
                $this->request($method, $replacement, [], $this->browserHeaders())->assertStatus(419);
            }
        }
    }

    public function testMaintenanceContinuesPastAnOperationOwnedByAnotherWorker(): void
    {
        $owner = $this->makeUser('owner');
        $first = $this->failedPrivacy($owner->id);
        $second = $this->failedPrivacy($owner->id);
        $this->browserLogin($this->makeUser('support'), 'support');
        foreach ([$first, $second] as $request) {
            $this->browserWrite('/operations/privacy/' . $request . '/retry', ['reason' => 'Storage issue corrected', 'idempotency_key' => 'privacy-recovery-' . $request])->assertStatus(303);
        }
        $operation = DB::table('staff_operations')->where('target_id', $first)->value('id');
        $lock = \Spark\Facades\Cache::lock('staff.operation.' . $operation, 60);
        $this->assertTrue($lock->acquire());
        try {
            (new \App\Jobs\StaffMaintenance())->handle();
            $this->assertDatabaseHas('staff_operations', ['target_id' => $first, 'status' => 'queued']);
            $this->assertDatabaseHas('staff_operations', ['target_id' => $second, 'status' => 'completed']);
        } finally {
            $lock->release();
        }
        (new \App\Jobs\StaffMaintenance())->handle();
        $this->assertDatabaseHas('staff_operations', ['target_id' => $first, 'status' => 'completed']);
    }

    private function failedPrivacy(int $user): int
    {
        return (int) DB::table('privacy_requests')->insert([
            'user_id' => $user, 'reference' => bin2hex(random_bytes(20)), 'kind' => 'export', 'status' => 'failed', 'attempts' => 5,
            'manifest' => Hash::encrypt('secret-manifest'), 'expires_at' => now()->addHours(24)->toDateTimeString(),
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ]);
    }
}
