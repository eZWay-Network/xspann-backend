<?php

namespace Tests\Feature;

use App\Jobs\PromotionMaintenance;
use App\Services\Billing\{Adjustments, Billing};
use App\Services\Promotions\{Availability, Boosts, Credits, Invalidations, Placements, Promotion};
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class PromotionDeliveryTest extends TestCase
{
    use PromotionFixtures;

    public function testNaturalShortfallAndLateIncidentReturnOnlyCumulativeUnusedCents(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $this->deliveredWindow($boost, 43200);
        query('promotion_boosts')->where('id', $boost->id)->update(['ends_at' => Billing::timestamp()]);
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $boost = Boosts::find($boost->id);
        $this->assertSame('interrupted', $boost->status);
        $this->assertSame(0, Credits::balance($owner->id)['available_cents']);
        $input = ['version' => $boost->version, 'action' => 'credit', 'reason' => 'Half the booked time was available.'];
        $result = Boosts::remedy($staff, $boost->id, $input, 'half-return-key');
        $this->assertSame(250, Credits::balance($owner->id)['available_cents']);
        $this->assertSame($result, Boosts::remedy($staff, $boost->id, $input, 'half-return-key'));
        Availability::record(['available' => false, 'reference' => 'outage-retrospective', 'note' => 'Earlier monitor gap confirmed as outage.',
            'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 43200), 'ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 21600)], $staff->id);
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $this->assertSame(375, Credits::balance($owner->id)['available_cents']);
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $this->assertSame(375, Credits::balance($owner->id)['available_cents']);
        $this->assertSame(2, query('promotion_ledger')->where('kind', 'reversal')->count());
    }

    public function testFractionalCentShortfallRoundsUpAndFullDeliveryIsNotAnAudienceGuarantee(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $this->deliveredWindow($boost, 86399);
        Boosts::cancel($owner, $boost->id, $boost->version);
        $this->assertSame(1, Credits::balance($owner->id)['available_cents']);
        $this->assertSame(499, Credits::balance($owner->id)['used_cents']);
        $this->assertSame(0, query('promotion_events')->count());
    }

    public function testOnlyObservedIntervalsCountAndOverlappingIncidentsAreNotDoubleSubtracted(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $now = time();
        query('promotion_boosts')->where('id', $boost->id)->update(['starts_at' => Billing::timestamp($now - 600), 'checked_at' => Billing::timestamp($now - 600)]);
        query('promotion_availability')->where('available', true)->update(['starts_at' => Billing::timestamp($now - 300), 'ends_at' => Billing::timestamp($now + 60)]);
        foreach ([[200, 100], [150, 50]] as $index => [$a, $b]) {
            Availability::record(['available' => false, 'reference' => 'overlap-' . $index, 'note' => 'Overlapping operator incident.',
                'starts_at' => gmdate('Y-m-d\TH:i:s\Z', $now - $a), 'ends_at' => gmdate('Y-m-d\TH:i:s\Z', $now - $b)]);
        }
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $this->assertSame(150, Availability::delivered(Boosts::find($boost->id)));
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $this->assertSame(150, Availability::delivered(Boosts::find($boost->id)));
    }

    public function testHideAndRestoreBetweenWorkerTicksNeverResumesOldCreative(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $this->asUser($owner)->patchJson('/api/v1/videos/' . $boost->video_id, ['visibility' => 'private'])->assertOk();
        $this->patchJson('/api/v1/videos/' . $boost->video_id, ['visibility' => 'public'])->assertOk();
        $this->flushHeaders()->getJson('/api/v1/promotions/placements?surface=web_promoted_videos')->assertOk()->assertJsonPath('data', []);
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $this->assertSame('interrupted', Boosts::find($boost->id)->status);
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        $this->assertSame(0, query('promotion_slots')->whereNull('released_at')->count());
    }

    public function testRefundRevokesUnusedSourceCreditWithoutInventingAnotherGrant(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $boost = $this->submittedBoost($owner);
        $invoice = query('billing_invoices')->first();
        $this->stripe->objects['/refunds/re_promotion'] = ['id' => 're_promotion', 'charge' => $invoice->charge_id,
            'status' => 'succeeded', 'amount' => 1999, 'currency' => 'usd'];
        $this->stripe->objects['/charges/' . $invoice->charge_id]['amount_refunded'] = 1999;
        $this->event('refund.created', 're_promotion');
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $this->assertSame('interrupted', Boosts::find($boost->id)->status);
        $this->assertSame(0, Credits::balance($owner->id)['available_cents']);
        $this->assertSame(0, Credits::balance($owner->id)['reserved_cents']);
        $this->event('invoice.paid', $invoice->invoice_id);
        $this->assertSame(0, Credits::balance($owner->id)['available_cents']);
        $this->assertSame(1, query('promotion_ledger')->where('kind', 'grant')->count());
        $this->assertSame(1, query('promotion_ledger')->where('kind', 'revocation')->count());
    }

    public function testDisputeHoldsAllSpendingAndRequiresRecordedResolution(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $invoice = query('billing_invoices')->first();
        $this->stripe->objects['/disputes/dp_promotion'] = ['id' => 'dp_promotion', 'charge' => $invoice->charge_id,
            'livemode' => false, 'status' => 'needs_response', 'amount' => 1999, 'currency' => 'usd'];
        $this->stripe->objects['/charges/' . $invoice->charge_id]['disputed'] = true;
        $this->event('charge.dispute.created', 'dp_promotion');
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $balance = Credits::balance($owner->id);
        $this->assertTrue($balance['spending_held']);
        $this->assertSame(0, $balance['available_cents']);
        $this->assertSame(null, Boosts::find($boost->id)->settled_at);
        Adjustments::allocate(query('billing_adjustments')->first()->id, 'released', 'The dispute has been reviewed and released.');
        query('promotion_boosts')->where('id', $boost->id)->update(['settle_after' => Billing::timestamp(time() - 1)]);
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        $this->assertSame('interrupted', Boosts::find($boost->id)->status);
    }

    public function testExtensionsRequireExactOwnerConsentAndNeverDebitTwice(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $this->deliveredWindow($boost, 43200);
        query('promotion_boosts')->where('id', $boost->id)->update(['ends_at' => Billing::timestamp()]);
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $boost = Boosts::find($boost->id);
        $proposal = ['version' => $boost->version, 'action' => 'propose_extension', 'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600), 'reason' => 'Offer the undelivered half-day.'];
        Boosts::remedy($staff, $boost->id, $proposal, 'extension-proposal');
        $placement = query('promotion_placements')->where('boost_id', $boost->id)->first();
        $boost = Boosts::find($boost->id);
        $this->assertSame(43200, Promotion::seconds($placement->extension_ends_at) - Promotion::seconds($placement->extension_starts_at));
        $this->postJson("/api/v1/admin/boosts/$boost->id/remedy", ['version' => $boost->version, 'action' => 'extend', 'placement_version' => $placement->version, 'reason' => 'Consent missing.'], ['Idempotency-Key' => 'extension-no-consent'])->assertStatus(409);
        $other = $this->makeUser('foreign');
        $this->asUser($other)->postJson("/api/v1/me/artist/placements/$placement->id/extension-consent", ['version' => $placement->version, 'accepted' => true])->assertNotFound();
        $placement = Placements::consent($owner, $placement->id, $placement->version, true);
        Boosts::remedy($staff, $boost->id, ['version' => $boost->version, 'action' => 'extend', 'placement_version' => $placement->version, 'reason' => 'Apply accepted dates.'], 'apply-extension');
        query('promotion_boosts')->where('id', $boost->id)->update(['starts_at' => Billing::timestamp(time() - 1)]);
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $this->assertSame('active', Boosts::find($boost->id)->status);
        $this->assertSame(1, query('promotion_ledger')->where('kind', 'debit')->count());
        $this->assertSame(0, Credits::balance($owner->id)['available_cents']);
    }

    public function testMissingWorkerWindowNeverBackdatesActivationAndExpiredReviewReleasesCredit(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $boost = $this->submittedBoost($owner);
        query('promotion_boosts')->where('id', $boost->id)->update(['starts_at' => Billing::timestamp(time() - 90000), 'ends_at' => Billing::timestamp(time() - 3600)]);
        (new PromotionMaintenance())->handle();
        $this->assertSame('rejected', Boosts::find($boost->id)->status);
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        $staff = $this->promotionStaff();
        $next = $this->submittedBoost($owner);
        Boosts::decide($staff, $next->id, ['version' => 2, 'decision' => 'approve', 'reason' => 'Approved before start.']);
        query('promotion_boosts')->where('id', $next->id)->update(['starts_at' => Billing::timestamp(time() - 90000), 'ends_at' => Billing::timestamp(time() - 3600)]);
        (new PromotionMaintenance())->handle();
        $this->assertSame('interrupted', Boosts::find($next->id)->status);
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        $this->assertSame(0, query('promotion_ledger')->where('kind', 'debit')->count());
    }

    public function testAuthoritativeEvidenceIsIdempotentAndPositiveWindowsCannotBeBackdated(): void
    {
        $this->promotionSetup();
        $this->promotionStaff();
        $input = ['available' => true, 'reference' => 'one-monitor-observation', 'note' => 'Verified the real rendered placement surface.'];
        $first = $this->postJson('/api/v1/admin/promotion-surface/availability', $input)->assertOk()->json('data');
        $this->postJson('/api/v1/admin/promotion-surface/availability', $input)->assertOk()->assertJsonPath('data', $first);
        $this->postJson('/api/v1/admin/promotion-surface/availability', [...$input, 'note' => 'Changed evidence'])->assertStatus(409);
        $this->postJson('/api/v1/admin/promotion-surface/availability', [...$input, 'reference' => 'backdate-attempt', 'starts_at' => '2026-10-01T00:00:00Z'])->assertStatus(422);
        $this->assertDatabaseCount('promotion_availability', 2);
    }

    public function testPartialRefundAllocationHoldsAndThenReleasesUnspentCredit(): void
    {
        [$owner] = $this->paidArtist();
        $invoice = query('billing_invoices')->first();
        Credits::balance($owner->id);
        $this->stripe->objects['/refunds/re_partial_boost'] = ['id' => 're_partial_boost', 'charge' => $invoice->charge_id,
            'status' => 'succeeded', 'amount' => 100, 'currency' => 'usd'];
        $this->stripe->objects['/charges/' . $invoice->charge_id]['amount_refunded'] = 100;
        $this->event('refund.created', 're_partial_boost');
        $this->assertSame(0, Credits::balance($owner->id)['available_cents']);
        $this->assertSame(500, Credits::balance($owner->id)['held_cents']);
        Adjustments::allocate(query('billing_adjustments')->first()->id, 'no_service_change', 'Partial adjustment does not revoke paid promotion benefits.');
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        $this->assertDatabaseCount('promotion_ledger', 1);
    }

    public function testReservationAcrossTwoPeriodsReleasesOnlyTheRefundedSourceCredit(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $this->deliveredWindow($boost, 43200);
        Boosts::cancel($owner, $boost->id, $boost->version);
        $this->assertSame(250, Credits::balance($owner->id)['available_cents']);
        $old = query('billing_invoices')->first();
        $remote = $this->stripe->objects['/invoices/' . $old->invoice_id];
        $renewal = $remote;
        $renewal['id'] = 'in_boost_renewal';
        $renewal['charge'] = 'ch_boost_renewal';
        $renewal['billing_reason'] = 'subscription_cycle';
        $renewal['lines']['data'][0]['period'] = ['start' => time() - 30, 'end' => time() + 30 * 86400];
        $this->stripe->objects['/invoices/in_boost_renewal'] = $renewal;
        $this->stripe->objects['/charges/ch_boost_renewal'] = [...$this->stripe->objects['/charges/' . $old->charge_id],
            'id' => 'ch_boost_renewal', 'invoice' => 'in_boost_renewal'];
        $this->event('invoice.paid', 'in_boost_renewal');
        $next = $this->submittedBoost($owner);
        $this->assertSame(2, query('promotion_reservations')->where('boost_id', $next->id)->count());
        $this->assertSame(250, Credits::balance($owner->id)['available_cents']);
        $this->stripe->objects['/refunds/re_old_period'] = ['id' => 're_old_period', 'charge' => $old->charge_id,
            'status' => 'succeeded', 'amount' => 1999, 'currency' => 'usd'];
        $this->stripe->objects['/charges/' . $old->charge_id]['amount_refunded'] = 1999;
        $this->event('refund.created', 're_old_period');
        Promotion::transaction(fn() => Boosts::tick($next->id));
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        $this->assertSame(0, Credits::balance($owner->id)['reserved_cents']);
        $this->assertSame(2, query('promotion_ledger')->where('kind', 'grant')->count());
    }

}
