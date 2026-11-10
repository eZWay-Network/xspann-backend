<?php

namespace Tests\Feature;

use App\Services\Billing\Billing;
use App\Services\Promotions\{Availability, Boosts, Credits, Promotion};
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class PromotionApiTest extends TestCase
{
    use PromotionFixtures;

    public function testOfferIsDisabledUntilActualSurfaceHasFreshIndependentEvidence(): void
    {
        $this->getJson('/api/v1/promotion-offers')->assertOk()->assertJsonPath('data.0.enabled', false)->assertJsonPath('data.0.capacity', 5);
        [$owner] = $this->paidArtist();
        $video = $this->makeVideo($owner);
        $id = $this->postJson('/api/v1/me/artist/boosts', $this->boostInput($video->id))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/me/artist/boosts/$id/submit", ['version' => 1], ['Idempotency-Key' => 'disabled-test'])->assertStatus(503);
        $this->assertDatabaseCount('promotion_reservations', 0);
        $this->promotionSetup();
        $this->getJson('/api/v1/promotion-offers')->assertOk()->assertJsonPath('data.0.enabled', true);
        query('promotion_availability')->where('id', '>', 0)->update(['ends_at' => Billing::timestamp(time() - 1)]);
        $this->getJson('/api/v1/promotion-offers')->assertOk()->assertJsonPath('data.0.enabled', false);
        $this->assertDatabaseCount('promotion_delivery', 0);
    }

    public function testPaidPeriodGrantsReconcileOnceAndSurviveNormalCancellation(): void
    {
        [$owner] = $this->paidArtist();
        $invoice = query('billing_invoices')->first();
        $this->getJson('/api/v1/me/artist/boost-balance')->assertOk()->assertJsonPath('data.available_cents', 500);
        $this->event('invoice.paid', $invoice->invoice_id);
        query('billing_subscriptions')->where('id', '>', 0)->update(['status' => 'canceled', 'active_key' => null]);
        query('billing_paid_periods')->where('id', '>', 0)->update(['ends_at' => Billing::timestamp(time() - 1)]);
        $this->getJson('/api/v1/me/artist/boost-balance')->assertOk()->assertJsonPath('data.available_cents', 500);
        $this->assertDatabaseCount('promotion_credits', 1);
        $this->assertDatabaseCount('promotion_ledger', 1);
        $this->getJson('/api/v1/me/artist/boost-ledger?limit=1')->assertOk()->assertJsonPath('data.0.kind', 'grant')->assertHeader('Cache-Control', 'no-store');
        $this->promotionSetup();
        $boost = $this->submittedBoost($owner);
        $this->assertSame('pending_review', $boost->status);
        $this->assertSame(500, Credits::balance($owner->id)['reserved_cents']);
        $other = $this->makeUser('other');
        $this->makeArtist($other);
        $this->asUser($other)->getJson('/api/v1/me/artist/boost-ledger')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v1/me/artist/boost-balance')->assertOk()->assertJsonPath('data.available_cents', 0);
    }

    public function testSubmitIsAtomicIdempotentVersionedAndOwnerScoped(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $video = $this->makeVideo($owner);
        $input = $this->boostInput($video->id);
        $id = $this->postJson('/api/v1/me/artist/boosts', $input)->assertCreated()->json('data.id');
        $first = $this->postJson("/api/v1/me/artist/boosts/$id/submit", ['version' => 1], ['Idempotency-Key' => 'submit-first'])->assertStatus(202)->json('data');
        $this->postJson("/api/v1/me/artist/boosts/$id/submit", ['version' => 1], ['Idempotency-Key' => 'submit-first'])->assertStatus(202)->assertJsonPath('data', $first);
        $this->postJson("/api/v1/me/artist/boosts/$id/submit", ['version' => 2], ['Idempotency-Key' => 'submit-first'])->assertStatus(409);
        $this->patchJson("/api/v1/me/artist/boosts/$id", ['version' => 2, ...$input])->assertStatus(409);
        $second = $this->postJson('/api/v1/me/artist/boosts', $input)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/me/artist/boosts/$second/submit", ['version' => 1], ['Idempotency-Key' => 'submit-other'])->assertStatus(409);
        $this->assertDatabaseCount('promotion_reservations', 1);
        $this->assertDatabaseCount('promotion_slots', 1);
        $other = $this->makeUser('intruder');
        $this->makeArtist($other);
        $this->asUser($other)->getJson("/api/v1/me/artist/boosts/$id")->assertNotFound();
        $this->postJson("/api/v1/me/artist/boosts/$id/cancel", ['version' => 2])->assertNotFound();
        $this->asUser($owner)->postJson("/api/v1/me/artist/boosts/$id/cancel", ['version' => 1])->assertStatus(409);
        $this->postJson("/api/v1/me/artist/boosts/$id/cancel", ['version' => 2])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->getJson('/api/v1/me/artist/boost-balance')->assertOk()->assertJsonPath('data.available_cents', 500)->assertJsonPath('data.reserved_cents', 0);
        $this->postJson("/api/v1/me/artist/boosts/$id/submit", ['version' => 1], ['Idempotency-Key' => 'submit-first'])->assertStatus(202)->assertJsonPath('data', $first);
    }

    public function testLegacyDraftRequiresAcceptingDiscoverTermsBeforeSubmission(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $input = $this->boostInput($this->makeVideo($owner)->id);
        $draft = Boosts::create($owner, $input);
        query('promotion_boosts')->where('id', $draft->id)->update([
            'offer_id' => 'boost-24h-usd-500-v1',
            'surface' => 'web_promoted_videos',
            'rights_version' => 'advertising-2026-10-31',
        ]);
        $this->postJson("/api/v1/me/artist/boosts/$draft->id/submit", ['version' => 1], ['Idempotency-Key' => 'legacy-draft'])->assertStatus(422);
        $this->assertDatabaseCount('promotion_slots', 0);
        $accepted = Boosts::edit($owner, $draft->id, [...$input, 'version' => 1]);
        $this->assertSame('discover_promoted_videos', $accepted->surface);
        Boosts::submit($owner, $draft->id, $accepted->version, 'accepted-discover');
        $this->assertSame(500, Credits::balance($owner->id)['reserved_cents']);
    }

    public function testCreativeAllowlistRightsAndDatesAreEnforced(): void
    {
        [$owner] = $this->paidArtist();
        $other = $this->makeUser('other');
        $public = $this->makeVideo($owner);
        $foreign = $this->makeVideo($other);
        $hidden = $this->makeVideo($owner, ['visibility' => 'private']);
        foreach ([$foreign->id, $hidden->id] as $id) {
            $this->postJson('/api/v1/me/artist/boosts', $this->boostInput($id))->assertStatus(422);
        }
        foreach ([
            ['price_cents' => 1],
            ['rights_confirmed' => 'true'],
            ['rights_confirmed' => false],
            ['rights_version' => 'old'],
            ['starts_at' => '2027-02-30T00:00:00Z'],
            ['starts_at' => '2027-01-01 12:00:00'],
            ['starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 1)]
        ] as $change) {
            $this->postJson('/api/v1/me/artist/boosts', [...$this->boostInput($public->id), ...$change])->assertStatus(422);
        }
        $this->assertDatabaseCount('promotion_boosts', 0);
        $this->promotionSetup();
        $id = $this->postJson('/api/v1/me/artist/boosts', $this->boostInput($public->id))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/me/artist/boosts/$id/submit", ['version' => 1])->assertStatus(422);
        $this->getJson('/api/v1/me/artist/boosts?limit=51')->assertStatus(422);
    }

    public function testStaffMfaAndStaleReviewAreEnforced(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $boost = $this->submittedBoost($owner);
        $this->browserPage('/promotions/boosts')->assertStatus(409);
        $this->promotionStaff();
        $this->browserPage('/promotions/boosts')->assertOk()->assertHeader('Cache-Control', 'no-store, private, max-age=0');
        $this->assertBrowserRejected($this->browserWrite("/promotions/boosts/$boost->id/decision", ['version' => 1, 'decision' => 'approve', 'reason' => 'Reviewed']));
        $this->browserWrite("/promotions/boosts/$boost->id/decision", ['version' => 2, 'decision' => 'reject', 'reason' => 'Advertising license is insufficient.'])->assertStatus(303);
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        query('staff_sessions')->where('id', '>', 0)->delete();
        $this->browserPage('/promotions/boosts')->assertStatus(409);
    }

    public function testActivationChargesOnceAndFetchesDoNotCreateDelivery(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $this->assertSame('active', $boost->status);
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $this->assertSame(1, query('promotion_ledger')->where('kind', 'debit')->count());
        $path = '/api/v1/promotions/placements?surface=discover_promoted_videos';
        $this->flushHeaders();
        foreach (['', '&platform=other', '&platform[]=web'] as $invalid) {
            $this->getJson($path . $invalid)->assertStatus(422);
        }
        foreach (Availability::PLATFORMS as $platform) {
            Availability::setEnabled($platform, true);
            Availability::record(['platform' => $platform, 'available' => true, 'reference' => 'client-smoke', 'note' => 'Verified Discover']);
            $response = $this->getJson($path . '&platform=' . $platform)->assertOk()
                ->assertHeader('Cache-Control', 'private, no-store')
                ->assertJsonPath('data.0.boost_id', $boost->id)->assertJsonPath('data.0.video.id', $boost->video_id)
                ->assertJsonPath('data.0.disclosure', 'Sponsored')->assertJsonPath('data.0.sponsor.user_id', $owner->id);
        }
        $this->assertDatabaseCount('promotion_slots', 1);
        Availability::setEnabled('web', false);
        $this->getJson($path . '&platform=web')->assertOk()->assertJsonPath('data', []);
        Availability::setEnabled('web', true);
        $this->getJson($path . '&platform=web')->assertOk()->assertJsonPath('data', []);
        $this->app->mergeConfig(['promotions' => ['bookings_enabled' => false]]);
        $this->getJson('/api/v1/promotion-offers')->assertJsonPath('data.0.enabled', false);
        $this->getJson($path . '&platform=android')->assertOk()->assertJsonPath('data.0.boost_id', $boost->id);
        $token = $response->json('data.0.event_token');
        $event = ['token' => $token, 'event_id' => $this->messageInput()['client_message_id'], 'type' => 'render'];
        $this->postJson('/api/v1/promotions/events', $event)->assertOk()->assertJsonPath('data.billable', false)->assertJsonPath('data.duplicate', false);
        $this->postJson('/api/v1/promotions/events', $event)->assertOk()->assertJsonPath('data.duplicate', true);
        $this->postJson('/api/v1/promotions/events', [...$event, 'type' => 'click'])->assertStatus(409);
        $this->postJson('/api/v1/promotions/events', [...$event, 'token' => $token . 'tampered'])->assertStatus(422);
        $this->assertSame(0, Availability::delivered($boost));
        Availability::setEnabled('ios', false);
        $this->postJson('/api/v1/promotions/events', [...$event, 'event_id' => $this->messageInput()['client_message_id'], 'type' => 'click'])->assertStatus(410);
        $this->assertDatabaseCount('promotion_events', 1);

        $viewer = $this->makeUser('blocked-viewer');
        \App\Models\Block::create(['blocker_id' => $viewer->id, 'blocked_id' => $owner->id]);
        $this->asUser($viewer)->getJson($path . '&platform=android')->assertOk()->assertJsonPath('data', []);
        $this->postJson('/api/v1/reports', ['boost_id' => $boost->id, 'reason' => 'Blocked placement'])->assertNotFound();
    }
}
