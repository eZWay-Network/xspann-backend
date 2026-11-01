<?php

namespace Tests\Feature;

use App\Jobs\PromotionMaintenance;
use App\Models\{Audio, User};
use App\Services\Billing\{Billing, Retention};
use App\Services\Promotions\{Availability, Boosts, Credits, Delivery, Invalidations, Placements, Privacy, Promotion, Reports};
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class PromotionAuditTest extends TestCase
{
    use PromotionFixtures;

    public function testUnchangedAudioPatchDoesNotInterruptAcceptedAdvertising(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $audio = Audio::create([
            'user_id' => $owner->id,
            'title' => 'Approved soundtrack',
            'storage_path' => "sounds/$owner->id/original.m4a",
            'origin' => 'upload',
            'status' => 'ready',
            'reuse_allowed' => true,
        ]);
        $video = $this->makeVideo($owner, ['audio_id' => $audio->id]);
        $boost = $this->activateCreative($owner, $staff, $video->id);
        $this->asUser($owner);
        $this->assertCount(1, Delivery::placements(null));

        foreach ([[], ['title' => $audio->title], ['reuse_allowed' => true]] as $input) {
            $this->patchJson('/api/v1/audios/' . $audio->id, $input)->assertOk();
            $this->assertCount(1, Delivery::placements(null));
        }
        $this->assertSame(null, Invalidations::cutoff($boost));
        $this->patchJson('/api/v1/audios/' . $audio->id, ['title' => 'Changed soundtrack attribution'])->assertOk();
        $this->assertSame([], Delivery::placements(null));
    }

    public function testRemovingOriginalAudioCannotReviveItsReviewedCampaign(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $video = $this->makeVideo($owner);
        $audio = Audio::create([
            'user_id' => $owner->id,
            'title' => 'Original soundtrack',
            'origin' => 'original',
            'source_video_id' => $video->id,
            'storage_path' => "sounds/$owner->id/original.m4a",
            'status' => 'ready',
            'reuse_allowed' => true,
            'rights_version' => config('policies.rights_version'),
            'rights_confirmed_at' => now()
        ]);
        $video->update(['audio_id' => $audio->id]);
        $boost = $this->activateCreative($owner, $staff, $video->id);
        $this->assertCount(1, Delivery::placements(null));
        $this->deliveredWindow($boost, 43200);
        $this->asUser($owner)->deleteJson("/api/v1/audios/$audio->id")->assertAccepted();
        $this->assertSame(null, $video->refresh()->audio_id);
        $this->assertSame([], Delivery::placements(null));
        $this->assertNotNull(Invalidations::cutoff(Boosts::find($boost->id)));
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $this->assertSame('interrupted', Boosts::find($boost->id)->status);
        $audio->refresh()->remove();
        $report = Reports::boosts([Boosts::find($boost->id)])[0]->report;
        $this->assertSame(43200, $report['delivered_seconds']);
        $this->assertSame(250, $report['returned_cents']);
        $this->assertSame(250, Credits::balance($owner->id)['available_cents']);
    }

    public function testPhysicalAudioRemovalPreservesDependentBoostInterruption(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $source = $this->makeUser('sound-owner');
        $audio = Audio::create([
            'user_id' => $source->id,
            'title' => 'Reusable music',
            'origin' => 'upload',
            'storage_path' => "sounds/$source->id/music.m4a",
            'status' => 'ready',
            'reuse_allowed' => true,
            'rights_version' => config('policies.rights_version'),
            'rights_confirmed_at' => now()
        ]);
        $video = $this->makeVideo($owner, ['audio_id' => $audio->id]);
        $boost = $this->activateCreative($owner, $staff, $video->id);
        $this->assertCount(1, Delivery::placements(null));
        $audio->remove();
        $this->assertSame(null, $video->refresh()->audio_id);
        $this->assertSame([], Delivery::placements(null));
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $this->assertSame('interrupted', Boosts::find($boost->id)->status);
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
    }

    public function testDeletedAccountDisputeSurvivesRecoveryStaffActionsAndRetention(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        query('billing_paid_periods')->where('id', '>', 0)->update(['promotion_hold' => true]);
        $owner->update(['status' => 'deleting']);
        Invalidations::record('user', $owner->id);
        Privacy::deleting($owner->id);
        $owner->remove();
        $old = Billing::timestamp(time() - 2556 * 86400);
        query('promotion_boosts')->where('id', $boost->id)->update(['status' => 'interrupted', 'settle_after' => $old, 'deleted_at' => $old]);
        query('promotion_credits')->whereNull('user_id')->update(['deleted_at' => $old]);
        query('billing_customers')->whereNull('user_id')->update(['deleted_at' => $old]);
        query('billing_subscriptions')->whereNull('user_id')->update(['active_key' => null]);
        (new PromotionMaintenance())->handle();
        Retention::run();
        Privacy::prune();
        $this->assertSame(null, Boosts::find($boost->id)->settled_at);
        $this->assertSame(0, query('promotion_ledger')->where('kind', 'reversal')->count());
        $this->assertDatabaseCount('billing_paid_periods', 1);
        $this->assertDatabaseCount('promotion_boosts', 1);
        $this->postJson("/api/v1/admin/boosts/$boost->id/remedy", [
            'version' => Boosts::find($boost->id)->version,
            'action' => 'credit',
            'reason' => 'Disputed credit remains held.'
        ], ['Idempotency-Key' => 'orphan-dispute-remedy'])->assertStatus(409);
        $held = Boosts::decide($staff, $boost->id, [
            'version' => Boosts::find($boost->id)->version,
            'decision' => 'hold',
            'reason' => 'Retain explicit staff review after account deletion.'
        ]);
        query('billing_paid_periods')->where('id', '>', 0)->update(['promotion_hold' => false]);
        Retention::run();
        $this->assertDatabaseCount('billing_paid_periods', 1);
        $released = Boosts::decide($staff, $boost->id, ['version' => $held->version, 'decision' => 'release_hold', 'reason' => 'Review completed.']);
        $this->assertNotNull($released->settled_at);
        $this->assertSame(1, query('promotion_ledger')->where('kind', 'reversal')->count());
        Privacy::prune();
        Retention::run();
        $this->assertDatabaseCount('promotion_boosts', 0);
        $this->assertDatabaseCount('billing_paid_periods', 0);
    }

    public function testLegacyAudioInvalidationKeepsItsOriginalCutoffAfterPhysicalDeletion(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $audio = Audio::create([
            'user_id' => $owner->id,
            'title' => 'Music',
            'origin' => 'upload',
            'storage_path' => "sounds/$owner->id/music.m4a",
            'status' => 'ready',
            'reuse_allowed' => true,
            'rights_version' => config('policies.rights_version'),
            'rights_confirmed_at' => now()
        ]);
        $boost = $this->activateCreative($owner, $staff, $this->makeVideo($owner, ['audio_id' => $audio->id])->id);
        $this->deliveredWindow($boost, 43200);
        $cutoff = Billing::timestamp(time() - 3600);
        // The original release wrote target-only events; the worker has not processed this one.
        query('promotion_invalidations')->insert(['target_type' => 'audio', 'target_id' => $audio->id, 'created_at' => $cutoff]);
        $audio->remove();
        $this->assertSame($cutoff, Invalidations::cutoff(Boosts::find($boost->id)));
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $report = Reports::boosts([Boosts::find($boost->id)])[0]->report;
        $this->assertSame(39600, $report['delivered_seconds']);
        $this->assertSame(271, $report['returned_cents']);
    }

    public function testDeletionAndExportCoverBothEnvironmentsWithoutCrossingEvidence(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $this->deliveredWindow($boost, 43200);
        $this->app->mergeConfig(['billing' => ['environment' => 'live']]);
        Availability::record([
            'available' => false,
            'reference' => 'live-only-outage',
            'note' => 'Must not affect test accounting.',
            'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 86400),
            'ends_at' => gmdate('Y-m-d\TH:i:s\Z')
        ]);
        $draft = Boosts::create($owner, $this->boostInput($this->makeVideo($owner)->id));
        $export = Privacy::export($owner->id);
        $this->assertCount(2, $export['boosts']);
        $this->assertCount(3, $export['ledger']);
        $this->assertSame(43200, Reports::boosts([query('promotion_boosts')->where('id', $boost->id)->first()])[0]->report['delivered_seconds']);
        $owner->update(['status' => 'deleting']);
        Invalidations::record('user', $owner->id);
        Privacy::deleting($owner->id);
        $this->assertSame(0, query('promotion_slots')->whereNull('released_at')->count());
        $this->assertSame('cancelled', query('promotion_boosts')->where('id', $boost->id)->value('status'));
        $this->assertNotNull(query('promotion_boosts')->where('id', $boost->id)->value('settled_at'));
        $this->assertNotNull(query('promotion_boosts')->where('id', $draft->id)->value('settled_at'));
        $this->assertSame(250, query('promotion_ledger')->where('kind', 'reversal')->value('available_delta'));
    }

    public function testRetiredInventoryStopsServingEventsAndFurtherCharges(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $token = Delivery::placements(null)[0]['event_token'];
        query('promotion_slots')->where('boost_id', $boost->id)->update(['slot' => 5]);
        $this->app->mergeConfig(['promotions' => ['capacity' => 4]]);
        $this->assertSame([], Delivery::placements(null));
        $this->flushHeaders()->postJson('/api/v1/promotions/events', [
            'token' => $token,
            'type' => 'render',
            'event_id' => '3f7808ec-1c69-4388-925d-787fc4e079b2'
        ])->assertStatus(410);
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $this->assertSame('interrupted', Boosts::find($boost->id)->status);
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        $this->assertDatabaseCount('promotion_delivery', 0);
        $this->app->mergeConfig(['promotions' => ['capacity' => 50]]);
        $this->assertSame(5, Availability::offer()['capacity']);
        $this->app->mergeConfig(['promotions' => ['capacity' => 0]]);
        $this->assertFalse(Availability::offer()['enabled']);
    }

    public function testDeletionReleasesAndRevokesReservedCreditInAnotherEnvironment(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $boost = $this->submittedBoost($owner);
        $credit = query('promotion_credits')->first();
        query('billing_paid_periods')->where('id', $credit->period_id)->update(['revoked_at' => Billing::timestamp()]);
        $this->app->mergeConfig(['billing' => ['environment' => 'live']]);
        $owner->update(['status' => 'deleting']);
        Invalidations::record('user', $owner->id);
        Privacy::deleting($owner->id);
        $this->assertSame(['available' => 0, 'reserved' => 0, 'used' => 0], Credits::amounts($credit->id));
        $this->assertSame('released', query('promotion_reservations')->where('boost_id', $boost->id)->value('status'));
        $this->assertSame(1, query('promotion_ledger')->where('kind', 'revocation')->count());
        $this->assertSame(0, query('promotion_slots')->whereNull('released_at')->count());
    }

    public function testUnspentDisputedCreditIsRetainedAfterOwnerDeletion(): void
    {
        [$owner] = $this->paidArtist();
        Credits::balance($owner->id);
        query('billing_paid_periods')->where('id', '>', 0)->update(['promotion_hold' => true]);
        $owner->update(['status' => 'deleting']);
        Privacy::deleting($owner->id);
        $owner->remove();
        query('promotion_credits')->whereNull('user_id')->update(['deleted_at' => Billing::timestamp(time() - 2556 * 86400)]);
        Privacy::prune();
        $this->assertDatabaseCount('promotion_credits', 1);
        $this->assertDatabaseCount('promotion_ledger', 1);
        query('billing_paid_periods')->where('id', '>', 0)->update(['promotion_hold' => false]);
        Privacy::prune();
        $this->assertDatabaseCount('promotion_credits', 0);
        $this->assertDatabaseCount('promotion_ledger', 0);
    }

    public function testRetiredSurfaceCannotServeOrActivateAnOlderCampaign(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $this->app->mergeConfig(['promotions' => ['surface' => 'replacement_surface']]);
        Availability::record(['available' => true, 'reference' => 'replacement-observation', 'note' => 'A different surface.']);
        $this->assertSame([], Delivery::placements(null));
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $this->assertSame('interrupted', Boosts::find($boost->id)->status);
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
    }

    public function testDeletionBeforeRecoveryDoesNotChargeTimeOnRetiredInventory(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $start = Billing::timestamp(time() - 3600);
        query('promotion_boosts')->where('id', $boost->id)->update(['starts_at' => $start, 'checked_at' => $start]);
        query('promotion_availability')->where('available', true)->update(['starts_at' => $start]);
        query('promotion_slots')->where('boost_id', $boost->id)->update(['slot' => 5]);
        $this->app->mergeConfig(['promotions' => ['capacity' => 4]]);
        $owner->update(['status' => 'deleting']);
        Invalidations::record('user', $owner->id);
        Privacy::deleting($owner->id);
        $this->assertDatabaseCount('promotion_delivery', 0);
        $this->assertSame(500, query('promotion_ledger')->where('kind', 'reversal')->value('available_delta'));
    }

    public function testCompletedHistoricalFeatureCanBeAttestedAfterLaterProfileChange(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner);
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $row = $this->pilot($staff, $artist->id);
        $end = time() - 10;
        query('promotion_placements')->where('id', $row->id)->update([
            'status' => 'active',
            'starts_at' => Billing::timestamp($end - 604800),
            'ends_at' => Billing::timestamp($end)
        ]);
        Invalidations::record('artist', $artist->id);
        $artist->update(['listed' => false]);
        Promotion::transaction(fn() => Placements::tick(Placements::find($row->id)));
        $row = Placements::find($row->id);
        $this->assertSame('awaiting_evidence', $row->status);
        $this->postJson("/api/v1/admin/placements/$row->id/evidence", [
            'version' => $row->version,
            'actual_starts_at' => gmdate('Y-m-d\TH:i:s\Z', $end - 604800),
            'actual_ends_at' => gmdate('Y-m-d\TH:i:s\Z', $end),
            'proof' => 'evidence:archive/completed-before-change',
            'note' => 'Full window completed before the profile change.'
        ])->assertOk();
    }

    public function testInvalidationDuringFeatureCannotBeErasedByDelayedWorkerOrEvidence(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner);
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $row = $this->pilot($staff, $artist->id);
        $end = time() - 10;
        query('promotion_placements')->where('id', $row->id)->update([
            'status' => 'active',
            'starts_at' => Billing::timestamp($end - 604800),
            'ends_at' => Billing::timestamp($end)
        ]);
        Invalidations::record('artist', $artist->id);
        query('promotion_invalidations')->where('target_type', 'artist')->update(['created_at' => Billing::timestamp($end - 60)]);
        $this->postJson("/api/v1/admin/placements/$row->id/evidence", [
            'version' => $row->version,
            'actual_starts_at' => gmdate('Y-m-d\TH:i:s\Z', $end - 604800),
            'actual_ends_at' => gmdate('Y-m-d\TH:i:s\Z', $end),
            'proof' => 'evidence:archive/interrupted-window',
            'note' => 'This window was interrupted.'
        ])->assertStatus(409);
        Promotion::transaction(fn() => Placements::tick(Placements::find($row->id)));
        $this->assertSame('interrupted', Placements::find($row->id)->status);
        $this->assertDatabaseCount('promotion_evidence', 0);
    }

    public function testSchedulingAnInvalidatedFutureFeatureRequiresOwnerConsent(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner);
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $row = $this->pilot($staff, $artist->id);
        $month = $row->month;
        $schedule = [
            'starts_at' => "$month-02" . 'T00:00:00Z',
            'ends_at' => "$month-09" . 'T00:00:00Z',
            'action' => 'schedule',
            'reason' => 'Confirmed first window.'
        ];
        $row = Placements::edit($staff, $row->id, ['version' => $row->version, ...$schedule]);
        Invalidations::record('artist', $artist->id);
        $row = Placements::edit($staff, $row->id, ['version' => $row->version, ...$schedule]);
        $this->assertSame('interrupted', $row->status);
        $this->assertSame('pending', $row->extension_consent);
        $row = Placements::consent($owner, $row->id, $row->version, true);
        $this->assertSame('scheduled', $row->status);
        $this->assertSame('accepted', $row->extension_consent);
    }

    public function testProofIdentityDistinguishesPostsButRejectsTrackingAliasesAndLegacyReuse(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner);
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $this->app->mergeConfig(['promotions' => ['proof_hosts' => ['www.youtube.com']]]);
        $first = $this->pilot($staff, $artist->id);
        $second = query('promotion_placements')->where('enrollment_id', $first->enrollment_id)->where('type', 'network_post')->first();
        $end = time() - 10;
        foreach ([$first, $second] as $row) {
            query('promotion_placements')->where('id', $row->id)->update([
                'status' => 'awaiting_evidence',
                'starts_at' => Billing::timestamp($end - 604800),
                'ends_at' => Billing::timestamp($end)
            ]);
        }
        $evidence = [
            'version' => 1,
            'actual_starts_at' => gmdate('Y-m-d\TH:i:s\Z', $end - 604800),
            'actual_ends_at' => gmdate('Y-m-d\TH:i:s\Z', $end),
            'note' => 'Operator verified this proof.'
        ];
        $this->postJson("/api/v1/admin/placements/$first->id/evidence", [...$evidence, 'proof' => 'https://www.youtube.com/watch?v=first'])->assertOk();
        // Simulate the immutable path-only hash written by the original Step 5 release.
        query('promotion_evidence')->where('placement_id', $first->id)->update(['proof_hash' => hash('sha256', 'https://www.youtube.com/watch')]);
        $this->postJson("/api/v1/admin/placements/$second->id/evidence", [
            ...$evidence,
            'proof' => 'https://www.youtube.com/watch?utm_source=mail&v=first#view'
        ])->assertStatus(409);
        $this->postJson("/api/v1/admin/placements/$second->id/evidence", [
            ...$evidence,
            'proof' => 'https://www.youtube.com/watch?v=second'
        ])->assertOk();
        $third = $this->pilot($staff, $this->makeArtist($this->makeUser('third'))->id);
        query('promotion_placements')->where('id', $third->id)->update([
            'status' => 'awaiting_evidence',
            'starts_at' => Billing::timestamp($end - 604800),
            'ends_at' => Billing::timestamp($end)
        ]);
        $this->postJson("/api/v1/admin/placements/$third->id/evidence", [
            ...$evidence,
            'proof' => 'https://WWW.youtube.com:443/watch/?v=second&utm_source=mail'
        ])->assertStatus(409);
        $this->assertDatabaseCount('promotion_evidence', 2);
    }

    private function activateCreative(User $owner, User $staff, int $video): object
    {
        $boost = Boosts::create($owner, $this->boostInput($video));
        Boosts::submit($owner, $boost->id, 1, 'audit-submit-' . $boost->id);
        Boosts::decide($staff, $boost->id, ['version' => 2, 'decision' => 'approve', 'reason' => 'Creative and rights reviewed.']);
        query('promotion_boosts')->where('id', $boost->id)->update(['starts_at' => Billing::timestamp(time() - 1), 'ends_at' => Billing::timestamp(time() + 86399)]);
        Promotion::transaction(fn() => Boosts::tick($boost->id));

        return Boosts::find($boost->id);
    }

    private function pilot(User $staff, int $artist): object
    {
        return Placements::create($staff, [
            'artist_id' => $artist,
            'month' => gmdate('Y-m', strtotime('first day of next month UTC')),
            'type' => 'feature',
            'network_account' => 'ezway-instagram',
            'capacity_confirmed' => true,
            'reason' => 'Operator confirmed both monthly obligations.'
        ]);
    }
}
