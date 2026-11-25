<?php

namespace Tests\Feature;

use App\Models\{Audio, Comment, User};
use App\Services\{Moderation, Releases};
use App\Services\Staff\Sessions;
use Spark\Facades\DB;
use Tests\Support\StaffBrowser;
use Tests\TestCase;

class StaffModerationActionsTest extends TestCase
{
    use StaffBrowser;

    private function act(string $type, int $id, string $action, array $extra = []): int
    {
        $target = Moderation::target($type, $id);
        $version = Moderation::versionColumn($type);
        $path = $type === 'user' ? "/users/$id/moderation" : "/content/$type/$id/moderation";
        $this->browserWrite($path, ['action' => $action, 'target_version' => (int) $target->$version,
            'reason_code' => 'safety', 'reason' => 'Reviewed policy violation.', ...$extra])->assertStatus(303);
        $this->assertSame([], session()->getFlash('errors', []));
        return (int) DB::table('moderation_decisions')->orderBy('id', 'desc')->value('id');
    }

    public function testDirectActionsNeedNoSecondReviewerAndRecordCompleteAudit(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner);
        $this->browserLogin($staff = $this->makeUser('moderator'), 'moderator');
        $decision = $this->act('video', $video->id, 'remove');
        $this->assertSame('removed', $video->refresh()->moderation_status);
        $report = DB::table('reports')->first();
        $this->assertSame((int) $staff->id, (int) $report->initiator_id);
        $this->assertSame('resolved', $report->status);
        $this->browserEvidence('/moderation/reports/' . $report->id)->assertOk();
        $event = DB::table('audit_events')->where('action', 'moderation.decided')->first();
        $context = json_decode($event->context, true);
        $this->assertSame($staff->id, (int) $event->actor_id);
        $this->assertSame($owner->id, (int) $event->affected_user_id);
        $this->assertSame($video->id, (int) $event->target_id);
        $this->assertSame('approved', $context['before']['status']);
        $this->assertSame('removed', $context['after']['status']);
        $this->assertSame('safety', $context['reason_code']);
        $this->browserPage('/operations/audit?actor_id=' . $staff->id . '&target_type=video&target_id=' . $video->id)->assertOk();
        $this->act('video', $video->id, 'restore', ['decision_id' => $decision]);
        $this->assertSame('approved', $video->refresh()->moderation_status);
    }

    public function testAccountRolesAndSensitiveConfirmationAreEnforced(): void
    {
        $owner = $this->makeUser('owner');
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        $this->act('user', $owner->id, 'warn');
        $this->browserWrite('/users/' . $owner->id . '/moderation', ['action' => 'ban', 'target_version' => 2,
            'reason_code' => 'safety', 'reason' => 'Policy violation.'])->assertForbidden();
        $this->assertSame('active', $owner->refresh()->status);
        $this->browserLogin($this->makeUser('senior'), 'senior_moderator');
        $this->browserWrite('/users/' . $owner->id . '/moderation', ['action' => 'soft_delete', 'target_version' => 2,
            'reason_code' => 'safety', 'reason' => 'Policy violation.'])->assertForbidden();
        $this->browserLogin($admin = $this->makeUser('admin'));
        DB::table('staff_sessions')->where('user_id', $admin->id)->update(['confirmed_at' => Sessions::timestamp(-600)]);
        $this->assertBrowserRejected($this->browserWrite('/users/' . $owner->id . '/moderation', ['action' => 'soft_delete', 'target_version' => 2,
            'reason_code' => 'safety', 'reason' => 'Policy violation.']));
        $this->browserPage('/users/' . $owner->id);
        $this->browserWrite('/challenge', ['password' => 'password123'])->assertStatus(303);
        $this->act('user', $owner->id, 'soft_delete');
        $this->assertSame('deleted', $owner->refresh()->status);
        $this->assertDatabaseCount('privacy_requests', 0);
    }

    public function testSuspensionRevokesAllAccessAndExpiryCannotLiftABan(): void
    {
        $owner = $this->makeUser('owner');
        $token = $this->token($owner);
        $this->browserLogin($this->makeUser('senior'), 'senior_moderator');
        $suspension = $this->act('user', $owner->id, 'suspend', ['expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]);
        $this->assertDatabaseCount('jwt_access_tokens', 0);
        $ban = $this->act('user', $owner->id, 'ban');
        DB::table('moderation_decisions')->where('id', $suspension)->update(['expires_at' => Sessions::timestamp(-1)]);
        Moderation::expireSuspensions();
        Moderation::expireSuspensions();
        $this->assertSame('banned', $owner->refresh()->status);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'moderation.suspension_expired')->count());
        $this->act('user', $owner->id, 'restore', ['decision_id' => $ban]);
        $this->assertSame('active', $owner->refresh()->status);
        $this->flushHeaders()->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function testUnbanAndAccountRestoreKeepSeparateContentRemoval(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner);
        $this->browserLogin($this->makeUser('admin'));
        $removal = $this->act('video', $video->id, 'remove');
        $ban = $this->act('user', $owner->id, 'ban');
        $deletion = $this->act('user', $owner->id, 'soft_delete');
        $this->act('user', $owner->id, 'restore', ['decision_id' => $deletion]);
        $this->assertSame('banned', $owner->refresh()->status);
        $this->act('user', $owner->id, 'restore', ['decision_id' => $ban]);
        $this->assertSame('active', $owner->refresh()->status);
        $this->assertSame('removed', $video->refresh()->moderation_status);
        $this->act('video', $video->id, 'restore', ['decision_id' => $removal]);
        $this->assertSame('approved', $video->refresh()->moderation_status);
    }

    public function testSoftDeletedVideoSurvivesCleanupAndCannotBeDeletedByOwner(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner);
        storage('public')->put($this->mediaKey($video->storage_path), 'retained-evidence');
        $this->browserLogin($staff = $this->makeUser('moderator'), 'moderator');
        $decision = $this->act('video', $video->id, 'soft_delete');
        (new \App\Jobs\FoundationMaintenance())->handle();
        $this->assertSame('published', $video->refresh()->status);
        $this->assertTrue($video->admin_deleted_at !== null);
        $this->assertTrue(storage('public')->exists($this->mediaKey($video->storage_path)));
        $this->flushHeaders()->asUser($owner)->deleteJson('/api/v1/videos/' . $video->id)->assertStatus(409);
        $this->browserAs($staff);
        $this->act('video', $video->id, 'restore', ['decision_id' => $decision]);
        $this->assertSame(null, $video->refresh()->admin_deleted_at);
    }

    public function testQuarantineCancelsScheduledPublicationAndRestoreDoesNotReschedule(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner, ['publication_state' => 'scheduled', 'publication_intent' => 'draft', 'publish_at' => Sessions::timestamp(3600), 'publish_timezone' => 'UTC']);
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        $decision = $this->act('video', $video->id, 'quarantine');
        $this->assertSame('draft', $video->refresh()->publication_state);
        $this->assertSame(null, $video->publish_at);
        $this->assertSame('reviewing', $video->moderation_status);
        $this->act('video', $video->id, 'restore', ['decision_id' => $decision]);
        app(Releases::class)->runDue($video->id);
        $this->assertSame('draft', $video->refresh()->publication_state);
    }

    public function testAudioReuseRestrictionAndWithdrawalHaveDifferentEffects(): void
    {
        $owner = $this->makeUser('owner');
        $audio = Audio::create(['user_id' => $owner->id, 'title' => 'Sound', 'origin' => 'upload', 'status' => 'ready', 'reuse_allowed' => true, 'storage_path' => 'sounds/1/test.mp3']);
        $video = $this->makeVideo($owner, ['audio_id' => $audio->id]);
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        $reuse = $this->act('audio', $audio->id, 'disable_reuse');
        $this->assertSame('approved', $video->refresh()->moderation_status);
        $this->assertSame(0, Audio::availableTo(null)->whereKey($audio->id)->count());
        $withdrawal = $this->act('audio', $audio->id, 'remove');
        $this->assertSame('removed', $video->refresh()->moderation_status);
        $this->act('audio', $audio->id, 'restore', ['decision_id' => $withdrawal]);
        $this->assertSame('approved', $video->refresh()->moderation_status);
        $this->assertSame(0, Audio::availableTo(null)->whereKey($audio->id)->count());
        $audio->update(['reuse_allowed' => false]);
        $this->act('audio', $audio->id, 'restore', ['decision_id' => $reuse]);
        $this->assertFalse($audio->refresh()->reuse_allowed);
    }

    public function testReportStatesAndStaleTargetCannotRepeatAnAction(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner);
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        $decision = $this->act('video', $video->id, 'quarantine');
        $report = DB::table('reports')->first();
        $this->browserWrite('/moderation/reports/' . $report->id . '/decision', ['action' => 'dismiss', 'version' => 2, 'reason' => 'No violation after review.'])->assertStatus(303);
        $this->assertDatabaseHas('reports', ['id' => $report->id, 'status' => 'dismissed']);
        $this->assertSame('approved', $video->refresh()->moderation_status);
        $this->assertBrowserRejected($this->browserWrite('/content/video/' . $video->id . '/moderation', ['action' => 'remove', 'target_version' => 1, 'reason_code' => 'other', 'reason' => 'Stale action must fail.']));
        $this->assertSame(1, DB::table('reports')->count());
        $this->assertSame('approved', $video->refresh()->moderation_status);
    }

    public function testAppealRequiresIndependentSeniorAndRestoresOnlyItsDecision(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner);
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        $decision = $this->act('video', $video->id, 'remove');
        $appeal = Moderation::appeal($owner, $decision, 'I have permission.');
        $input = ['outcome' => 'overturn', 'version' => 1, 'reason' => 'Permission verified.'];
        $this->browserWrite('/moderation/appeals/' . $appeal['id'] . '/decision', $input)->assertForbidden();
        $this->browserLogin($this->makeUser('senior'), 'senior_moderator');
        $this->browserWrite('/moderation/appeals/' . $appeal['id'] . '/decision', $input)->assertStatus(303);
        $this->assertSame('approved', $video->refresh()->moderation_status);
        $this->assertDatabaseHas('moderation_appeals', ['id' => $appeal['id'], 'status' => 'overturned', 'resolution' => 'Permission verified.']);
        $this->assertDatabaseHas('notifications', ['user_id' => $owner->id, 'type' => 'moderation_appeal']);
    }

    public function testDeletedAccountHasAppealOnlyAccessAndLastSuperAdminIsProtected(): void
    {
        $owner = $this->makeUser('owner');
        $this->browserLogin($admin = $this->makeUser('admin'));
        $this->act('user', $owner->id, 'soft_delete');
        $this->flushHeaders()->asUser($owner)->getJson('/api/v1/me/moderation-decisions')->assertOk();
        $this->getJson('/api/v1/me/subscription')->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertForbidden();
        $this->browserAs($admin);
        $this->browserWrite('/users/' . $admin->id . '/moderation', ['action' => 'ban', 'target_version' => 1, 'reason_code' => 'other', 'reason' => 'Cannot remove myself.'])->assertForbidden();
        $this->assertSame('active', $admin->refresh()->status);
    }

    public function testCommentContextAndRequiredConfirmationFields(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner);
        $parent = Comment::create(['user_id' => $owner->id, 'video_id' => $video->id, 'body' => 'Parent context']);
        $reply = Comment::create(['user_id' => $owner->id, 'video_id' => $video->id, 'parent_id' => $parent->id, 'body' => 'Reply context']);
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        $page = $this->browserPage('/content/comments/' . $reply->id)->assertOk();
        $this->assertTrue(str_contains($page->content(), 'Parent context'));
        $this->assertBrowserRejected($this->browserWrite('/content/comment/' . $reply->id . '/moderation', ['action' => 'remove', 'target_version' => 1, 'reason' => 'Missing reason code.']));
        $this->assertDatabaseCount('moderation_decisions', 0);
        $this->browserPage('/content/comments/' . $reply->id);
        $this->act('comment', $parent->id, 'remove');
        $this->assertSame('removed', $reply->refresh()->moderation_status);
    }
    public function testDeletedReportTargetCanBeDismissedWithoutLosingEvidence(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner);
        $report = Moderation::report($this->makeUser('reporter'), ['video_id' => $video->id, 'reason' => 'Reported before owner deletion.']);
        DB::table('videos')->where('id', $video->id)->delete();
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        $this->browserPage('/moderation/reports/' . $report->id)->assertOk()->assertJsonPath('props.moderation.choices', []);
        $this->browserWrite('/moderation/reports/' . $report->id . '/decision', [
            'action' => 'dismiss', 'version' => 1, 'target_version' => 1, 'reason_code' => 'other', 'reason' => 'Content already deleted.',
        ])->assertStatus(303);
        $this->assertDatabaseHas('reports', ['id' => $report->id, 'status' => 'dismissed']);
        $this->browserEvidence('/moderation/reports/' . $report->id)->assertOk();
    }

    public function testLongExplanationsAndActionAuditFilters(): void
    {
        $owner = $this->makeUser('owner');
        $this->browserLogin($staff = $this->makeUser('admin'));
        $reason = str_repeat('Reviewed evidence. ', 40);
        $this->act('user', $owner->id, 'ban', ['reason' => $reason]);
        $this->assertSame(trim($reason), DB::table('moderation_decisions')->value('reason'));
        $this->assertTrue(mb_strlen(DB::table('reports')->value('reason')) <= 255);
        $this->browserPage('/operations/audit?action=ban&affected_user_id=' . $owner->id)
            ->assertOk()->assertJsonCount(1, 'props.rows')->assertJsonPath('props.rows.0.audit.after.status', 'banned');
    }

    public function testExpiredSuspensionDoesNotBreakMaintenanceAfterPermanentDeletion(): void
    {
        $owner = $this->makeUser('owner');
        $this->browserLogin($this->makeUser('senior'), 'senior_moderator');
        $decision = $this->act('user', $owner->id, 'suspend', ['expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)]);
        DB::table('users')->where('id', $owner->id)->delete();
        DB::table('moderation_decisions')->where('id', $decision)->update(['expires_at' => Sessions::timestamp(-1)]);
        Moderation::expireSuspensions();
        $this->assertDatabaseHas('moderation_decisions', ['id' => $decision, 'active' => false]);
        $this->assertDatabaseHas('audit_events', ['action' => 'moderation.suspension_expired', 'target_id' => $owner->id]);
    }

}
