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
        foreach ([['price_cents' => 1], ['rights_confirmed' => 'true'], ['rights_confirmed' => false], ['rights_version' => 'old'],
            ['starts_at' => '2027-02-30T00:00:00Z'], ['starts_at' => '2027-01-01 12:00:00'], ['starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 1)]] as $change) {
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
        $this->getJson('/api/v1/admin/boosts')->assertForbidden();
        $this->promotionStaff();
        $this->getJson('/api/v1/admin/boosts')->assertOk()->assertHeader('Cache-Control', 'no-store');
        $this->postJson("/api/v1/admin/boosts/$boost->id/decision", ['version' => 1, 'decision' => 'approve', 'reason' => 'Reviewed'])->assertStatus(409);
        $this->postJson("/api/v1/admin/boosts/$boost->id/decision", ['version' => 2, 'decision' => 'reject', 'reason' => 'Advertising license is insufficient.'])->assertOk();
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        query('staff_sessions')->where('id', '>', 0)->delete();
        $this->getJson('/api/v1/admin/boosts')->assertForbidden();
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
        $response = $this->flushHeaders()->getJson('/api/v1/promotions/placements?surface=web_promoted_videos')->assertOk();
        $response->assertJsonPath('data.0.disclosure', 'Sponsored')->assertJsonPath('data.0.sponsor.user_id', $owner->id);
        $token = $response->json('data.0.event_token');
        $event = ['token' => $token, 'event_id' => $this->messageInput()['client_message_id'], 'type' => 'render'];
        $this->postJson('/api/v1/promotions/events', $event)->assertOk()->assertJsonPath('data.billable', false)->assertJsonPath('data.duplicate', false);
        $this->postJson('/api/v1/promotions/events', $event)->assertOk()->assertJsonPath('data.duplicate', true);
        $this->postJson('/api/v1/promotions/events', [...$event, 'type' => 'click'])->assertStatus(409);
        $this->postJson('/api/v1/promotions/events', [...$event, 'token' => $token . 'tampered'])->assertStatus(422);
        $this->assertSame(0, Availability::delivered($boost));
        $this->assertDatabaseCount('promotion_events', 1);
    }
}
