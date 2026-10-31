<?php

namespace Tests\Feature;

use App\Http\Resources\PromotionResource;
use App\Jobs\{ProcessPrivacyRequest, PromotionMaintenance};
use App\Services\Billing\Billing;
use App\Services\PrivacyRequests;
use App\Services\Promotions\{Boosts, Credits, Privacy, Promotion, Reports};
use Spark\Facades\Hash;
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class PromotionLifecycleTest extends TestCase
{
    use PromotionFixtures;

    public function testPopulatedStepFourUpgradeReconcilesOldPeriodsAndPreservesAllExistingRecords(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $video = $this->makeVideo($owner);
        $migration = require dirname(__DIR__, 2) . '/database/migrations/migration_2026_10_31_210000_promotions.php';
        $migration->down();
        $migration->up();
        (new PromotionMaintenance())->handle();
        (new PromotionMaintenance())->handle();
        $this->assertDatabaseCount('promotion_credits', 1);
        $this->assertDatabaseCount('promotion_ledger', 1);
        $this->assertDatabaseCount('billing_paid_periods', 1);
        $this->assertDatabaseCount('artists', 1);
        $this->assertDatabaseCount('videos', 1);
        $this->assertSame($owner->id, $video->refresh()->user_id);
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        $this->assertThrows(\RuntimeException::class, fn() => $migration->down());
        $this->assertDatabaseCount('promotion_ledger', 1);
        $this->assertSame([], db()->getPdo()->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function testPrivacyExportAndDeletionSuppressPromotionAndRetainOnlyAnonymousAccounting(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $this->asUser($owner);
        $proof = $this->postJson('/api/v1/auth/reauthenticate', ['purpose' => 'export_data', 'password' => 'password123'])->assertOk()->json('data.proof');
        $id = $this->postJson('/api/v1/me/export-requests', ['proof' => $proof])->assertAccepted()->json('data.id');
        (new ProcessPrivacyRequest($id))->handle();
        $export = json_decode(Hash::decrypt(storage('local')->get(PrivacyRequests::exportPath($id))), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($boost->id, $export['promotions']['boosts'][0]['id']);
        $this->assertCount(3, $export['promotions']['ledger']);
        $proof = $this->postJson('/api/v1/auth/reauthenticate', ['purpose' => 'delete_account', 'password' => 'password123'])->assertOk()->json('data.proof');
        $this->postJson('/api/v1/me/deletion-requests', ['proof' => $proof])->assertAccepted();
        $this->flushHeaders()->getJson('/api/v1/promotions/placements?surface=web_promoted_videos')->assertOk()->assertJsonPath('data', []);
        $deletion = query('privacy_requests')->where('kind', 'deletion')->first();
        (new ProcessPrivacyRequest($deletion->id))->handle();
        $this->assertSame(null, query('promotion_boosts')->first()->user_id);
        $this->assertSame(null, query('promotion_boosts')->first()->artist_id);
        $this->assertSame(null, query('promotion_credits')->first()->user_id);
        $this->assertSame(0, query('promotion_slots')->whereNull('released_at')->count());
        $this->assertDatabaseCount('promotion_operations', 0);
        $this->assertSame([], db()->getPdo()->query('PRAGMA foreign_key_check')->fetchAll());
        foreach (['promotion_boosts', 'promotion_credits', 'promotion_enrollments'] as $table) {
            query($table)->whereNull('user_id')->update(['deleted_at' => Billing::timestamp(time() - 2556 * 86400)]);
        }
        Privacy::prune();
        $this->assertDatabaseCount('promotion_boosts', 0);
        $this->assertDatabaseCount('promotion_credits', 0);
        $this->assertDatabaseCount('promotion_ledger', 0);
    }

    public function testListsUseBoundedQueriesAndResourcesNeverQuery(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        for ($i = 0; $i < 8; $i++) {
            Boosts::create($owner, $this->boostInput($this->makeVideo($owner)->id));
        }
        $this->app->mergeConfig(['app' => ['debug' => true]]);
        $queries = 0;
        event()->addListener('app:db.queryExecuted', function () use (&$queries): void { $queries++; });
        $this->getJson('/api/v1/me/artist/boosts?limit=1')->assertOk();
        $small = $queries;
        $queries = 0;
        $this->getJson('/api/v1/me/artist/boosts?limit=8')->assertOk()->assertJsonCount(8, 'data');
        $this->assertSame($small, $queries);
        $rows = Reports::boosts(query('promotion_boosts')->get()->all());
        $queries = 0;
        foreach ($rows as $row) {
            PromotionResource::boost($row);
        }
        $this->assertSame(0, $queries);
    }

    public function testFailedReservationRollsBackInventoryLedgerAndIdempotencyTogether(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $boost = Boosts::create($owner, $this->boostInput($this->makeVideo($owner)->id));
        db()->statement("CREATE TRIGGER promotion_failure BEFORE INSERT ON promotion_slots BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $this->assertThrows(\PDOException::class, fn() => Boosts::submit($owner, $boost->id, 1, 'retry-after-failure'));
        $this->assertSame('draft', Boosts::find($boost->id)->status);
        $this->assertDatabaseCount('promotion_ledger', 0);
        $this->assertDatabaseCount('promotion_reservations', 0);
        $this->assertDatabaseCount('promotion_operations', 0);
        db()->statement('DROP TRIGGER promotion_failure');
        Boosts::submit($owner, $boost->id, 1, 'retry-after-failure');
        $this->assertSame(500, Credits::balance($owner->id)['reserved_cents']);
    }

    public function testStaffHoldAlsoPreventsSpendingReleasedPreActivationCredit(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->submittedBoost($owner);
        $held = Boosts::decide($staff, $boost->id, ['version' => 2, 'decision' => 'hold', 'reason' => 'Explicit fraud review hold.']);
        $balance = Credits::balance($owner->id);
        $this->assertSame(0, $balance['available_cents']);
        $this->assertSame(500, $balance['held_cents']);
        $draft = Boosts::create($owner, $this->boostInput($this->makeVideo($owner)->id));
        $this->asUser($owner)->postJson("/api/v1/me/artist/boosts/$draft->id/submit", ['version' => 1], ['Idempotency-Key' => 'held-spending-attempt'])->assertForbidden();
        Boosts::decide($staff, $boost->id, ['version' => $held->version, 'decision' => 'release_hold', 'reason' => 'Fraud review cleared.']);
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        $this->assertSame('interrupted', Boosts::find($boost->id)->status);
    }

    public function testEnvironmentIsolationAndAutomaticShortfallSettlement(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        query('promotion_boosts')->where('id', $boost->id)->update(['ends_at' => Billing::timestamp()]);
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        query('promotion_boosts')->where('id', $boost->id)->update(['settle_after' => Billing::timestamp(time() - 1)]);
        (new PromotionMaintenance())->handle();
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        $this->app->mergeConfig(['billing' => ['environment' => 'live']]);
        $this->assertSame(0, Credits::balance($owner->id)['available_cents']);
        $this->asUser($owner)->getJson("/api/v1/me/artist/boosts/$boost->id")->assertNotFound();
        $this->getJson('/api/v1/me/artist/boost-ledger')->assertOk()->assertJsonPath('data', []);
    }

    public function testCancellationDuringDisputeSettlesAfterResolutionAndRetry(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        query('billing_paid_periods')->where('id', '>', 0)->update(['promotion_hold' => true]);
        $cancelled = Boosts::cancel($owner, $boost->id, $boost->version);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(null, $cancelled->settled_at);
        query('billing_paid_periods')->where('id', '>', 0)->update(['promotion_hold' => false]);
        query('promotion_boosts')->where('id', $boost->id)->update(['settle_after' => Billing::timestamp(time() - 1)]);
        (new PromotionMaintenance())->handle();
        (new PromotionMaintenance())->handle();
        $this->assertSame(500, Credits::balance($owner->id)['available_cents']);
        $this->assertSame(1, query('promotion_ledger')->where('kind', 'reversal')->count());
        $this->assertNotNull(Boosts::find($boost->id)->settled_at);
    }

    public function testDeletedDraftDoesNotEscapeAccountingRetention(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $draft = Boosts::create($owner, $this->boostInput($this->makeVideo($owner)->id));
        $owner->update(['status' => 'deleting']);
        Privacy::deleting($owner->id);
        $owner->remove();
        query('promotion_boosts')->where('id', $draft->id)->update(['deleted_at' => Billing::timestamp(time() - 2556 * 86400)]);
        Privacy::prune();
        $this->assertDatabaseCount('promotion_boosts', 0);
    }

    public function testUnusedMonthlyObligationBecomesInterruptedWithoutInventedDelivery(): void
    {
        $owner = $this->makeUser();
        $artist = $this->makeArtist($owner);
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        \App\Services\Promotions\Placements::create($staff, ['artist_id' => $artist->id, 'month' => gmdate('Y-m'),
            'type' => 'feature', 'network_account' => 'ezway-instagram', 'capacity_confirmed' => true, 'reason' => 'Confirmed pilot.']);
        query('promotion_placements')->where('artist_id', $artist->id)->update(['due_at' => Billing::timestamp(time() - 1)]);
        (new PromotionMaintenance())->handle();
        $this->assertSame(2, query('promotion_placements')->where('status', 'interrupted')->count());
        $this->assertDatabaseCount('promotion_evidence', 0);
    }

    public function testSuccessfulZeroAudienceDeliveryConsumesCreditAndNeverReturnsIt(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $this->deliveredWindow($boost, 86400);
        query('promotion_boosts')->where('id', $boost->id)->update(['ends_at' => Billing::timestamp()]);
        Promotion::transaction(fn() => Boosts::tick($boost->id));
        $this->assertSame('completed', Boosts::find($boost->id)->status);
        $this->assertSame(500, Credits::balance($owner->id)['used_cents']);
        $this->assertSame(0, Credits::balance($owner->id)['available_cents']);
        $this->assertDatabaseCount('promotion_events', 0);
        $this->assertSame(0, query('promotion_ledger')->where('kind', 'reversal')->count());
    }


    public function testProductionRegistrationRunsPromotionRecoveryOnItsNamedQueue(): void
    {
        [$owner] = $this->paidArtist();
        $this->app->withQueue(then: require dirname(__DIR__, 2) . '/bootstrap/jobs.php');
        $queue = \Spark\Facades\Queue::connection();
        $this->assertCount(1, $queue->getJobs(queue: 'promotions'));
        $queue->work(once: true, timeout: 5, sleep: 0, queue: 'promotions');
        $this->assertSame(1, query('promotion_ledger')->where('kind', 'grant')->count());
        $this->assertCount(1, $queue->getJobs(queue: 'promotions'));
        $this->assertSame([], $queue->getFailedJobs());
    }

}
