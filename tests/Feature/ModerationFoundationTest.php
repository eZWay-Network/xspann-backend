<?php

namespace Tests\Feature;

use App\Models\{Audio, Comment, Report, Video};
use App\Services\{AccountSecurity, Moderation, Totp};
use Tests\TestCase;

class ModerationFoundationTest extends TestCase
{
    use \Tests\Support\StaffBrowser;

    private function staff(string $name): \App\Models\User
    {
        $user = $this->makeUser($name);
        $this->browserLogin($user, 'moderator');

        return $user;
    }

    public function testRightsRestrictionPropagatesAndIndependentAppealRestoresOnlyItsDecision(): void
    {
        $owner = $this->makeUser();
        $fan = $this->makeUser('fan');
        $source = $this->makeVideo($owner);
        $audio = Audio::create(['user_id' => $owner->id, 'title' => 'Original', 'storage_path' => 'sounds/1/test.m4a', 'source_video_id' => $source->id, 'origin' => 'original', 'status' => 'ready', 'reuse_allowed' => true]);
        $remix = $this->makeVideo($fan, ['audio_id' => $audio->id]);
        $report = $this->asUser($fan)->postJson('/api/v1/reports', ['video_id' => $source->id, 'reason' => 'Rights concern'])->assertStatus(201)->json('data.id');
        $this->assertFalse(str_contains(query('reports')->where('id', $report)->first()->evidence, 'Hello #world'));
        $this->asUser($owner)->getJson('/api/v1/me/reports/' . $report)->assertStatus(404);
        $reviewer = $this->staff('reviewer');
        $appealReviewer = $this->staff('appealreviewer');
        $this->browserAs($reviewer)->browserEvidence('/moderation/reports/' . $report)->assertOk()->assertJsonPath('snapshot.caption', 'Hello #world');
        $this->browserWrite('/moderation/reports/' . $report . '/decision', ['action' => 'restrict', 'reason' => 'License missing.', 'version' => 1])->assertStatus(303);
        $decision = (int) query('moderation_decisions')->orderBy('id', 'desc')->value('id');
        $this->assertBrowserRejected($this->browserWrite('/moderation/reports/' . $report . '/decision', ['action' => 'dismiss', 'reason' => 'Changed.', 'version' => 1]));
        foreach ([$source, $remix] as $video) {
            $this->getJson('/api/v1/videos/' . $video->id)->assertStatus(404);
        }
        $this->getJson('/api/v1/audios/' . $audio->id)->assertStatus(404);
        $appeal = $this->asUser($owner)->postJson('/api/v1/me/moderation-appeals', ['decision_id' => $decision, 'explanation' => 'Here is the license.'])->assertStatus(201)->json('data.id');
        $this->postJson('/api/v1/me/moderation-appeals', ['decision_id' => $decision, 'explanation' => 'Again'])->assertStatus(409);
        $this->asUser($fan)->postJson('/api/v1/me/moderation-appeals', ['decision_id' => $decision, 'explanation' => 'Not mine'])->assertStatus(404);
        $this->browserAs($appealReviewer)->browserPage('/moderation/appeals')->assertOk()->assertJsonPath('props.rows.0.id', $appeal);
        $this->browserPage('/moderation/reports/' . $report)->assertOk()->assertJsonPath('props.decisions.0.id', $decision);
        $input = ['outcome' => 'overturn', 'reason' => 'License confirmed.', 'version' => 1];
        $this->browserAs($reviewer)->browserWrite('/moderation/appeals/' . $appeal . '/decision', $input)->assertStatus(403);
        $this->browserAs($appealReviewer)->browserWrite('/moderation/appeals/' . $appeal . '/decision', $input)->assertStatus(303);
        $this->flushHeaders()->getJson('/api/v1/videos/' . $remix->id)->assertOk();
        $this->assertDatabaseHas('audit_events', ['action' => 'moderation.evidence_viewed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'moderation.appeal_decided']);
        $this->assertDatabaseCount('moderation_restrictions', 0);
    }

    public function testMultipleRestrictionsDoNotRestoreEachOtherAndEvidenceSurvivesDeletion(): void
    {
        $owner = $this->makeUser();
        $fan = $this->makeUser('fan');
        $video = $this->makeVideo($owner);
        $first = Moderation::report($fan, ['video_id' => $video->id, 'reason' => 'First']);
        $second = Moderation::report($fan, ['video_id' => $video->id, 'reason' => 'Second']);
        $reviewer = $this->staff('reviewer');
        $other = $this->staff('otherreviewer');
        $this->browserAs($reviewer);
        foreach ([$first, $second] as $report) {
            $this->browserWrite('/moderation/reports/' . $report->id . '/decision', ['action' => 'restrict', 'reason' => 'Restricted.', 'version' => 1])->assertStatus(303);
        $decisions[] = (int) query('moderation_decisions')->orderBy('id', 'desc')->value('id');
        }
        $appeal = $this->asUser($owner)->postJson('/api/v1/me/moderation-appeals', ['decision_id' => $decisions[0], 'explanation' => 'Review please.'])->assertStatus(201)->json('data.id');
        $this->browserAs($other)->browserWrite('/moderation/appeals/' . $appeal . '/decision', ['outcome' => 'overturn', 'reason' => 'First resolved.', 'version' => 1])->assertStatus(303);
        $this->assertSame('restricted', $video->refresh()->moderation_status);
        $this->browserWrite('/moderation/reports/' . $second->id . '/decision', ['action' => 'restore', 'reason' => 'Second case resolved after review.', 'version' => 2])->assertStatus(303);
        $this->assertSame('approved', $video->refresh()->moderation_status);
        $video->remove();
        $this->assertSame(null, $first->refresh()->video_id);
        $this->browserEvidence('/moderation/reports/' . $first->id)->assertOk()->assertJsonPath('snapshot.caption', 'Hello #world');
    }

    public function testReportTargetsRespectVisibilityBlocksAndParentCommentRestrictions(): void
    {
        $owner = $this->makeUser();
        $fan = $this->makeUser('fan');
        $private = $this->makeVideo($owner, ['visibility' => 'private']);
        $video = $this->makeVideo($owner);
        $parent = Comment::create(['user_id' => $owner->id, 'video_id' => $video->id, 'body' => 'Parent']);
        $reply = Comment::create(['user_id' => $fan->id, 'video_id' => $video->id, 'parent_id' => $parent->id, 'body' => 'Reply']);
        $this->asUser($fan)->postJson('/api/v1/reports', ['video_id' => $private->id, 'reason' => 'Private'])->assertStatus(404);
        $this->postJson('/api/v1/reports', ['video_id' => $video->id, 'comment_id' => $parent->id, 'reason' => 'Ambiguous'])->assertStatus(422);
        $report = $this->postJson('/api/v1/reports', ['comment_id' => $parent->id, 'reason' => 'Abuse'])->assertStatus(201)->json('data.id');
        $this->staff('reviewer');
        $this->browserWrite('/moderation/reports/' . $report . '/decision', ['action' => 'restrict', 'reason' => 'Abusive thread.', 'version' => 1])->assertStatus(303);
        $this->getJson('/api/v1/comments/' . $parent->id . '/replies')->assertStatus(404);
        $this->assertSame('restricted', $reply->refresh()->moderation_status);
        $this->getJson('/api/v1/videos/' . $video->id . '/comments')->assertJsonCount(0, 'data');
    }

    public function testSuspensionKeepsAppealAccessAndHidesAudioDerivatives(): void
    {
        $owner = $this->makeUser();
        $fan = $this->makeUser('fan');
        $source = $this->makeVideo($owner);
        $report = Moderation::report($fan, ['reported_user_id' => $owner->id, 'reason' => 'Account concern']);
        $this->staff('reviewer');
        $this->browserWrite('/moderation/reports/' . $report->id . '/decision', ['action' => 'restrict', 'reason' => 'Suspended pending review.', 'version' => 1])->assertStatus(303);
        $decision = (int) query('moderation_decisions')->orderBy('id', 'desc')->value('id');
        $this->assertSame('suspended', $owner->refresh()->status);
        $this->asUser($owner)->getJson('/api/v1/auth/me')->assertStatus(403);
        $this->getJson('/api/v1/me/moderation-decisions')->assertOk()->assertJsonPath('data.0.id', $decision);
        $this->postJson('/api/v1/me/moderation-appeals', ['decision_id' => $decision, 'explanation' => 'Please reconsider.'])->assertStatus(201);
    }
}
