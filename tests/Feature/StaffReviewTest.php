<?php

namespace Tests\Feature;

use App\Services\Moderation;
use Spark\Facades\DB;
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class StaffReviewTest extends TestCase
{
    use PromotionFixtures;

    public function testMalformedRecordReferencesAndCursorsFailCleanly(): void
    {
        $this->browserLogin($this->makeUser('admin'));
        foreach (['users', 'artists', 'content/videos', 'content/audios', 'content/playlists', 'moderation/reports', 'moderation/appeals', 'promotions/boosts', 'operations/privacy', 'operations/actions'] as $path) {
            $this->browserPage('/' . $path . '/create')->assertNotFound();
        }
        $this->browserPage('/promotions/placements/create')->assertOk();
        foreach (['invalid!', base64_encode('{"other":1,"_pointsToNextItems":true}'), base64_encode('{"id":true,"_pointsToNextItems":true}')] as $cursor) {
            $this->browserPage('/promotions/credits?cursor=' . urlencode($cursor))->assertStatus(422);
        }
    }

    public function testDisclosuresRejectPageRequestsAndPrefetchBeforeAuditing(): void
    {
        $owner = $this->makeUser('owner');
        $report = Moderation::report($this->makeUser('reporter'), ['video_id' => $this->makeVideo($owner)->id, 'reason' => 'Review reported content', 'details' => 'Private review context']);
        $this->browserLogin($this->makeUser('admin'));
        foreach ([['X-Inertia' => 'true'], ['X-Inertia-Partial-Component' => 'moderation/reports/show'], ['X-Inertia-Partial-Data' => 'snapshot'], ['Purpose' => 'prefetch'], ['Sec-Purpose' => 'prefetch;prerender']] as $headers) {
            $response = $this->get('/moderation/reports/' . $report->id . '/evidence', $headers)->assertStatus(400);
            $this->assertFalse(str_contains($response->content(), 'Private review context'));
            $this->get('/moderation/reports/' . $report->id . '/media/original', $headers)->assertStatus(400);
            $this->post('/users/' . $owner->id . '/contact-reveal', ['reason' => 'Customer requested support'], ['X-CSRF-TOKEN' => session('csrf_token'), ...$headers])->assertStatus(400);
        }
        $this->assertSame(0, DB::table('audit_events')->whereIn('action', ['moderation.evidence_viewed', 'moderation.media_viewed', 'staff.contact_revealed'])->count());
        $invalid = $this->post('/users/' . $owner->id . '/contact-reveal', ['reason' => '     '], ['Accept' => 'application/json', 'X-CSRF-TOKEN' => session('csrf_token')])->assertStatus(422);
        $this->assertTrue(count($invalid->json('errors.reason')) > 0);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'staff.contact_revealed')->count());
        $this->browserEvidence('/moderation/reports/' . $report->id)->assertOk()->assertJsonPath('details', 'Private review context');
        $this->assertSame(1, DB::table('audit_events')->where('action', 'moderation.evidence_viewed')->count());
    }

    public function testDefaultPriorityTriageAuditAndResolvedActionsStayConsistent(): void
    {
        $owner = $this->makeUser('owner');
        $report = Moderation::report($this->makeUser('reporter'), ['video_id' => $this->makeVideo($owner)->id, 'reason' => 'Review reported content']);
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        $this->browserPage('/moderation/reports?priority=normal')->assertOk()->assertJsonPath('props.rows.0.id', $report->id);
        $this->browserWrite('/moderation/reports/' . $report->id . '/assignment', ['version' => 0, 'priority' => 'high', 'tag' => 'rights'], 'PATCH')->assertStatus(303);
        $this->browserPage('/moderation/reports?priority=normal')->assertOk()->assertJsonPath('props.rows', []);
        $this->browserPage('/moderation/rights?priority=high')->assertOk()->assertJsonPath('props.rows.0.id', $report->id)->assertJsonPath('props.filters.tag', 'rights');
        $this->browserPage('/moderation/rights?tag=spam')->assertStatus(422);
        $audit = json_decode(DB::table('audit_events')->where('action', 'moderation.assigned')->value('context'), true);
        $this->assertSame(0, $audit['before']['version']);
        $this->assertSame(1, $audit['after']['version']);
        $this->assertSame('rights', $audit['after']['tag']);
        $this->assertSame(1, (int) DB::table('reports')->value('version'));
        $this->assertBrowserRejected($this->browserWrite('/moderation/reports/' . $report->id . '/notes', ['body' => '   ']));
        $this->assertDatabaseCount('report_notes', 0);
        $this->browserWrite('/moderation/reports/' . $report->id . '/decision', ['action' => 'dismiss', 'version' => 1, 'reason' => 'No violation found'])->assertStatus(303);
        $this->browserPage('/moderation/reports/' . $report->id)->assertOk()->assertJsonPath('props.actions.decision', null);
    }

    public function testPlacementCalendarUsesValidMonthsAndHalfOpenUtcDays(): void
    {
        [, $artist] = $this->paidArtist();
        $this->promotionSetup();
        $this->browserLogin($operator = $this->makeUser('operator'), 'promotion_operator');
        $id = DB::table('promotion_placements')->insert(['environment' => 'test', 'user_id' => $artist->user_id, 'artist_id' => $artist->id, 'type' => 'feature', 'destination' => 'sponsored', 'status' => 'scheduled', 'month' => '2026-11', 'starts_at' => '2026-11-01 00:00:00', 'ends_at' => '2026-11-02 00:00:00', 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00']);
        $this->browserPage('/promotions/placements?month=2026-11&day=2026-11-01')->assertOk()->assertJsonPath('props.rows.0.id', (int) $id);
        $this->browserPage('/promotions/placements?day=2026-11-02')->assertOk()->assertJsonPath('props.rows', []);
        DB::table('staff_preferences')->insert(['user_id' => $operator->id, 'timezone' => 'Asia/Dhaka']);
        $this->browserPage('/promotions/placements?day=2026-11-02')->assertOk()->assertJsonPath('props.rows.0.id', (int) $id);
        $this->browserPage('/promotions/placements?day=2026-11-03')->assertOk()->assertJsonPath('props.rows', []);
        $this->browserPage('/promotions/placements?month=2026-13')->assertStatus(422);
        $this->browserPage('/promotions/placements?day=2026-11-31')->assertStatus(422);
    }
    public function testCaseMediaPreservesRangeFailuresAndRejectsMissingOriginals(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner, ['storage_path' => 'local:videos/' . $owner->id . '/sample.mp4']);
        storage('local')->put('videos/' . $owner->id . '/sample.mp4', '0123456789');
        $report = Moderation::report($this->makeUser('reporter'), ['video_id' => $video->id, 'reason' => 'Review reported content']);
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        $path = '/moderation/reports/' . $report->id . '/media/original';
        $this->get($path, ['Range' => 'bytes=2-5'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 2-5/10')->assertSee('2345');
        $this->get($path, ['Range' => 'bytes=20-'])->assertStatus(416)->assertHeader('Content-Range', 'bytes */10')->assertHeader('Cache-Control', 'no-store, private, max-age=0');
        storage('local')->delete('videos/' . $owner->id . '/sample.mp4');
        $this->get($path)->assertNotFound();
    }

    public function testDeliveryHistoryLedgerAndRemediesRespectCampaignState(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->makeUser('operator');
        $boost = $this->activateBoost($owner, $staff);
        $this->deliveredWindow($boost, 300);
        DB::table('promotion_delivery')->insert(['boost_id' => $boost->id, 'starts_at' => gmdate('Y-m-d H:i:s', time() - 600), 'ends_at' => gmdate('Y-m-d H:i:s', time() - 300)]);
        $this->browserLogin($staff);
        $path = '/promotions/boosts/' . $boost->id;
        $page = $this->browserPage($path . '?per_page=1')->assertOk()->assertJsonPath('props.remedy_options', []);
        $this->assertSame(1, count($page->json('props.delivery.rows')));
        $next = $page->json('props.delivery.next_url');
        $this->assertTrue(str_contains($next, 'per_page=1'));
        $nextPage = $this->browserPage($next)->assertOk();
        $this->assertFalse($page->json('props.delivery.rows.0.id') === $nextPage->json('props.delivery.rows.0.id'));
        $this->assertSame(null, $nextPage->json('props.delivery.next_url'));
        DB::table('promotion_boosts')->where('id', $boost->id)->update(['ends_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        \App\Services\Promotions\Promotion::transaction(fn () => \App\Services\Promotions\Boosts::tick($boost->id));
        $this->browserPage($path)->assertOk()->assertJsonPath('props.remedy_options', ['credit', 'propose_extension']);
        $boost = \App\Services\Promotions\Boosts::find($boost->id);
        \App\Services\Promotions\Boosts::decide($staff, $boost->id, ['version' => $boost->version, 'decision' => 'hold', 'reason' => 'Review pending payment evidence']);
        $this->browserPage($path)->assertOk()->assertJsonPath('props.remedy_url', null);
        $row = \App\Services\Promotions\Boosts::find($boost->id);
        \App\Services\Promotions\Boosts::decide($staff, $boost->id, ['version' => $row->version, 'decision' => 'release_hold', 'reason' => 'Payment review completed']);
        $this->browserPage($path)->assertOk()->assertJsonPath('props.remedy_options', ['credit']);
        $row = \App\Services\Promotions\Boosts::find($boost->id);
        \App\Services\Promotions\Boosts::remedy($staff, $boost->id, ['action' => 'credit', 'version' => $row->version, 'reason' => 'Return recorded unused time'], 'review-credit-one');
        $this->browserPage($path)->assertOk()->assertJsonPath('props.remedy_options', ['credit']);
        $ledger = $this->browserPage('/promotions/credits?owner_id=' . $owner->id . '&per_page=1&sort=oldest')->assertOk();
        $this->assertSame(500, $ledger->json('props.balances.charged_cents'));
        $this->assertTrue($ledger->json('props.balances.returned_cents') > 0);
        $next = $ledger->json('props.pagination.next_url');
        $this->assertTrue(str_contains($next, 'owner_id=' . $owner->id));
        $second = $this->browserPage($next)->assertOk();
        $this->assertFalse($ledger->json('props.rows.0.id') === $second->json('props.rows.0.id'));
        $previous = $this->browserPage($second->json('props.pagination.previous_url'))->assertOk();
        $this->assertSame($ledger->json('props.rows.0.id'), $previous->json('props.rows.0.id'));
        $this->browserPage('/promotions/credits?q=' . $ledger->json('props.rows.0.id'))->assertOk()->assertJsonPath('props.balances.charged_cents', 500);
        $this->app->mergeConfig(['billing' => ['environment' => 'live']]);
        $this->browserPage($path)->assertNotFound();
    }

    public function testArtistPlansArePermissionScopedAndPlaylistItemsAreFullyPaged(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $playlist = \App\Models\ArtistPlaylist::create(['artist_id' => $artist->id, 'title' => 'Full playlist', 'visibility' => 'public', 'category' => 'release']);
        for ($index = 1; $index <= 51; $index++) {
            $video = $this->makeVideo($owner, ['caption' => 'Track ' . $index]);
            DB::table('artist_playlist_items')->insert(['playlist_id' => $playlist->id, 'video_id' => $video->id, 'position' => $index]);
        }
        $this->browserLogin($this->makeUser('admin'));
        $page = $this->browserPage('/artists')->assertOk();
        $this->assertTrue(str_contains($page->json('props.rows.0.description'), 'Plan: artist_pro'));
        $this->browserPage('/content/playlists/' . $playlist->id . '?per_page=50&page=2')->assertOk()
            ->assertJsonPath('props.items.pagination.total', 51)->assertJsonPath('props.items.rows.0.label', 'Track 51');
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        $page = $this->browserPage('/artists')->assertOk();
        $this->assertFalse(str_contains($page->content(), 'artist_pro'));
    }

}
