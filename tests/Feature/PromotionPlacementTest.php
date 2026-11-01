<?php

namespace Tests\Feature;

use App\Services\Billing\Billing;
use App\Services\Promotions\{Invalidations, Placements, Promotion};
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class PromotionPlacementTest extends TestCase
{
    use PromotionFixtures;

    private function enrollmentInput(int $artist): array
    {
        return ['artist_id' => $artist, 'month' => gmdate('Y-m', strtotime('first day of next month UTC')),
            'type' => 'feature', 'network_account' => 'ezway-instagram', 'capacity_confirmed' => true,
            'reason' => 'Operator confirmed both monthly obligations.'];
    }

    public function testEnrollmentCreatesExactlyTwoSeparateObligationsWithoutRequiringPro(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner);
        $this->promotionSetup();
        $this->promotionStaff();
        $input = $this->enrollmentInput($artist->id);
        $this->browserWrite('/promotions/placements', $input)->assertStatus(303);
        $this->assertDatabaseHas('promotion_placements', ['type' => 'feature']);
        $this->assertDatabaseCount('promotion_enrollments', 1);
        $this->assertDatabaseCount('promotion_placements', 2);
        $this->assertBrowserRejected($this->browserWrite('/promotions/placements', $input));
        $this->assertBrowserRejected($this->browserWrite('/promotions/placements', [...$input, 'type' => 'network_post']));
        $this->asUser($owner)->getJson('/api/v1/me/artist/placements')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['feature', 'network_post'], query('promotion_placements')->orderBy('id')->pluck('type'));
        $other = $this->makeUser('outside');
        $this->makeArtist($other);
        $this->asUser($other)->getJson('/api/v1/me/artist/placements')->assertOk()->assertJsonPath('data', []);
    }

    public function testUnconfirmedCapacityAndConcurrentFeatureWindowsAreRejected(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner);
        $this->promotionSetup();
        $this->promotionStaff();
        $input = $this->enrollmentInput($artist->id);
        $this->assertBrowserRejected($this->browserWrite('/promotions/placements', [...$input, 'capacity_confirmed' => false]));
        $this->browserWrite('/promotions/placements', [...$input, 'network_account' => 'not-confirmed'])->assertStatus(503);
        $month = $input['month'];
        $schedule = ['starts_at' => "$month-02" . 'T00:00:00Z', 'ends_at' => "$month-09" . 'T00:00:00Z'];
        $this->browserWrite('/promotions/placements', [...$input, ...$schedule])->assertStatus(303);
        $second = $this->makeArtist($this->makeUser('second'));
        $this->assertBrowserRejected($this->browserWrite('/promotions/placements', [...$input, ...$schedule, 'artist_id' => $second->id]));
        $this->assertDatabaseCount('promotion_enrollments', 1);
        $this->assertDatabaseCount('promotion_placements', 2);
        $this->browserWrite('/promotions/placements', [...$input, 'artist_id' => $second->id,
            'starts_at' => "$month-09" . 'T00:00:00Z', 'ends_at' => "$month-16" . 'T00:00:00Z'])->assertStatus(303);
    }

    public function testEvidenceMustBePastDistinctAndImmutableAndCannotInventSevenDays(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner);
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        Placements::create($staff, $this->enrollmentInput($artist->id));
        $rows = query('promotion_placements')->orderBy('id')->get();
        $feature = $rows[0];
        $social = $rows[1];
        $end = time() - 10;
        query('promotion_placements')->where('id', $feature->id)->update(['starts_at' => Billing::timestamp($end - 604800),
            'ends_at' => Billing::timestamp($end), 'status' => 'awaiting_evidence']);
        $input = ['version' => 1, 'actual_starts_at' => gmdate('Y-m-d\TH:i:s\Z', $end - 604800),
            'actual_ends_at' => gmdate('Y-m-d\TH:i:s\Z', $end), 'proof' => 'evidence:archive/seven-day-feature', 'note' => 'Actual seven-day operator archive.'];
        $this->assertBrowserRejected($this->browserWrite("/promotions/placements/$feature->id/evidence", [...$input, 'actual_starts_at' => gmdate('Y-m-d\TH:i:s\Z', $end - 600)]));
        $this->assertBrowserRejected($this->browserWrite("/promotions/placements/$feature->id/evidence", [...$input, 'proof' => 'http://unapproved.example/proof']));
        $this->browserWrite("/promotions/placements/$feature->id/evidence", $input)->assertStatus(303);
        $this->assertDatabaseHas('promotion_placements', ['id' => $feature->id, 'status' => 'completed']);
        $this->browserWrite("/promotions/placements/$feature->id/evidence", $input)->assertStatus(303);
        $this->assertBrowserRejected($this->browserWrite("/promotions/placements/$feature->id/evidence", [...$input, 'note' => 'Different record.']));
        $this->assertSame('planned', query('promotion_placements')->where('id', $social->id)->value('status'));
        query('promotion_placements')->where('id', $social->id)->update(['starts_at' => Billing::timestamp($end - 3600),
            'ends_at' => Billing::timestamp($end), 'status' => 'awaiting_evidence']);
        $socialInput = [...$input, 'actual_starts_at' => gmdate('Y-m-d\TH:i:s\Z', $end - 60), 'actual_ends_at' => gmdate('Y-m-d\TH:i:s\Z', $end - 60)];
        $this->assertBrowserRejected($this->browserWrite("/promotions/placements/$social->id/evidence", $socialInput));
        $this->browserWrite("/promotions/placements/$social->id/evidence", [...$socialInput, 'proof' => 'https://www.instagram.com/p/unique-post/'])->assertStatus(303);
        $this->assertDatabaseCount('promotion_evidence', 2);
        $this->assertSame(2, query('audit_events')->where('action', 'promotion.placement.delivered')->count());
        $this->assertSame(0, query('promotion_placements')->where('type', 'tv')->count());
    }

    public function testFeaturedVisibilityIsSeparateAndRestorationCannotEraseAnInterruption(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner);
        $fan = $this->makeUser('fan');
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $row = Placements::create($staff, $this->enrollmentInput($artist->id));
        query('promotion_placements')->where('id', $row->id)->update(['status' => 'scheduled', 'starts_at' => Billing::timestamp(time() - 60), 'ends_at' => Billing::timestamp(time() + 604740)]);
        $this->flushHeaders()->getJson('/api/v1/artists/featured')->assertOk()->assertJsonPath('data.0.artist.id', $artist->id)->assertJsonPath('data.0.disclosure', 'Complimentary pilot feature');
        $this->asUser($fan)->postJson('/api/v1/users/' . $owner->id . '/block')->assertCreated();
        $this->getJson('/api/v1/artists/featured')->assertOk()->assertJsonPath('data', []);
        $this->flushHeaders()->getJson('/api/v1/artists/featured')->assertOk()->assertJsonCount(1, 'data');
        Invalidations::record('artist', $artist->id);
        $this->getJson('/api/v1/artists/featured')->assertOk()->assertJsonPath('data', []);
        Promotion::transaction(fn() => Placements::tick(Placements::find($row->id)));
        $this->assertSame('interrupted', Placements::find($row->id)->status);
    }

    public function testInterruptedMonthlyDatesRequireVersionedOwnerConsent(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner);
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $row = Placements::create($staff, $this->enrollmentInput($artist->id));
        $row = Placements::edit($staff, $row->id, ['version' => 1, 'action' => 'interrupt', 'reason' => 'Operator delivery unavailable.']);
        $input = ['version' => $row->version, 'action' => 'schedule', 'starts_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
            'ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600 + 604800), 'reason' => 'Offer replacement seven-day window.'];
        $row = Placements::edit($staff, $row->id, $input);
        $this->assertSame('interrupted', $row->status);
        $this->assertSame(null, $row->starts_at);
        $this->asUser($owner)->postJson("/api/v1/me/artist/placements/$row->id/extension-consent", ['version' => $row->version - 1, 'accepted' => true])->assertStatus(409);
        $this->postJson("/api/v1/me/artist/placements/$row->id/extension-consent", ['version' => $row->version, 'accepted' => true])->assertOk()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.extension.consent', 'accepted');
        $this->assertDatabaseCount('promotion_evidence', 0);
    }
}
