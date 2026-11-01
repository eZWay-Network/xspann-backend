<?php

namespace Tests\Feature;

use App\Jobs\PromotionMaintenance;
use App\Models\{Block, Report, Video};
use App\Services\Moderation;
use App\Services\Promotions\{Boosts, Credits, Delivery};
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class BoostReportTest extends TestCase
{
    use PromotionFixtures;

    public function testVisibleSponsoredPlacementCanBeReportedWithPrivateEvidence(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $fan = $this->makeUser('viewer');
        $id = $this->asUser($fan)->postJson('/api/v1/reports', [
            'boost_id' => $boost->id,
            'reason' => 'Misleading sponsored placement',
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('reports', [
            'id' => $id,
            'boost_id' => $boost->id,
            'target_type' => 'boost',
            'target_id' => $boost->id,
            'subject_user_id' => $owner->id,
        ]);
        $evidence = json_decode(Report::find($id)->evidence, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($boost->video_id, $evidence['video_id']);
        $this->assertSame('Sponsored', $evidence['disclosure']);
        $this->assertSame('Artist', $evidence['sponsor']['stage_name']);
        $this->assertFalse(str_contains(query('reports')->where('id', $id)->value('evidence'), 'Sponsored'));
        $this->getJson("/api/v1/me/reports/$id")->assertOk()->assertJsonPath('data.status', 'open');
        $this->getJson("/api/v1/admin/reports/$id")->assertForbidden();

        $this->promotionStaff('reviewer');
        $this->getJson('/api/v1/admin/reports?type=boost')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/reports/$id")->assertOk()->assertJsonPath('data.evidence.video_id', $boost->video_id);
        $this->assertSame(1, query('audit_events')->where('action', 'moderation.evidence_viewed')->count());
    }

    public function testBoostReportsRejectOwnHiddenOtherEnvironmentAndMultipleTargets(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $input = ['boost_id' => $boost->id, 'reason' => 'Review sponsored placement'];
        $this->asUser($owner)->postJson('/api/v1/reports', $input)->assertUnprocessable();
        $fan = $this->makeUser('viewer');
        $this->asUser($fan)->postJson('/api/v1/reports', [...$input, 'video_id' => $boost->video_id])->assertUnprocessable();
        $placement = Delivery::placements($fan)[0];
        query('promotion_boosts')->where('id', $boost->id)->update(['starts_at' => \App\Services\Billing\Billing::timestamp(time() + 60)]);
        $this->assertSame([], Delivery::placements($fan));
        $this->postJson('/api/v1/reports', $input)->assertNotFound();
        $this->postJson('/api/v1/promotions/events', [
            'token' => $placement['event_token'],
            'event_id' => '12345678-1234-4234-8234-123456789012',
            'type' => 'click',
        ])->assertStatus(410);
        query('promotion_boosts')->where('id', $boost->id)->update(['starts_at' => $boost->starts_at]);
        Block::create(['blocker_id' => $fan->id, 'blocked_id' => $owner->id]);
        $this->postJson('/api/v1/reports', $input)->assertNotFound();
        Block::where('blocker_id', $fan->id)->delete();
        $this->app->mergeConfig(['billing' => ['environment' => 'live']]);
        $this->postJson('/api/v1/reports', $input)->assertNotFound();
        $this->app->mergeConfig(['billing' => ['environment' => 'test']]);
        Video::whereKey($boost->video_id)->update(['visibility' => 'private']);
        $this->postJson('/api/v1/reports', $input)->assertNotFound();
        Video::whereKey($boost->video_id)->update(['visibility' => 'public']);
        query('promotion_boosts')->where('id', $boost->id)->update(['status' => 'draft']);
        $this->postJson('/api/v1/reports', $input)->assertNotFound();
        $this->assertDatabaseCount('reports', 0);
    }

    public function testBoostRestrictionStopsDeliveryAndSettlesOnceWithoutRevivalOnAppeal(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $fan = $this->makeUser('viewer');
        $this->asUser($fan);
        $placements = Delivery::placements($fan);
        $this->assertCount(1, $placements);
        $id = $this->postJson('/api/v1/reports', [
            'boost_id' => $boost->id,
            'reason' => 'Review advertising claim',
        ])->assertCreated()->json('data.id');
        $decision = Moderation::decide($id, $staff, ['action' => 'restrict', 'version' => 1, 'reason' => 'Advertising claim requires correction.']);
        $this->assertSame([], Delivery::placements($fan));
        $this->postJson('/api/v1/promotions/events', [
            'token' => $placements[0]['event_token'],
            'event_id' => '12345678-1234-4234-8234-123456789012',
            'type' => 'click',
        ])->assertStatus(410);
        (new PromotionMaintenance())->handle();
        (new PromotionMaintenance())->handle();
        $this->assertSame('interrupted', Boosts::find($boost->id)->status);
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        $this->assertSame(1, query('promotion_ledger')->where('kind', 'reversal')->count());
        $this->assertSame('approved', Video::find($boost->video_id)->moderation_status);

        $appeal = Moderation::appeal($owner, $decision['decision_id'], 'Please independently review the claim.');
        $reviewer = $this->makeUser('independent');
        Moderation::decideAppeal($appeal['id'], $reviewer, ['outcome' => 'overturn', 'version' => 1, 'reason' => 'Reviewed the supporting evidence.']);
        (new PromotionMaintenance())->handle();
        $this->assertSame([], Delivery::placements($fan));
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        $this->assertSame(0, query('moderation_restrictions')->where('target_type', 'boost')->count());
        query('promotion_boosts')->where('id', $boost->id)->delete();
        $this->assertSame(null, Report::find($id)->boost_id);
        $this->assertSame($boost->id, Report::find($id)->target_id);
        $this->assertSame($boost->video_id, json_decode(Report::find($id)->evidence, true)['video_id']);
    }
}
