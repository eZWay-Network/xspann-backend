<?php

namespace Tests\Feature;

use App\Services\Staff\{Exports, Operations, Sessions};
use Spark\Facades\{DB, Storage};
use Tests\Support\{ArtistFixtures, StaffBrowser};
use Tests\TestCase;

class StaffSettingsExportTest extends TestCase
{
    use ArtistFixtures, StaffBrowser;

    public function testSettingsUseTypedReadersRejectUnknownFieldsAndEnforceVersions(): void
    {
        $this->browserLogin($this->makeUser('admin'));
        foreach (['general', 'catalog', 'policies', 'taxonomy', 'integrations', 'roles'] as $page) {
            $response = $this->browserPage('/settings/' . $page)->assertOk();
            $this->assertFalse(str_contains($response->content(), config('app.key')));
        }
        $input = ['key' => 'workspace_name', 'value' => 'XPN Operations', 'version' => 0];
        $this->browserWrite('/settings/general', $input, 'PATCH')->assertStatus(303);
        $this->browserPage('/')->assertOk()->assertJsonPath('props.workspace.name', 'XPN Operations');
        $this->assertBrowserRejected($this->browserWrite('/settings/general', $input, 'PATCH'));
        $this->assertBrowserRejected($this->browserWrite('/settings/general', ['key' => 'support_url', 'value' => 'javascript:alert(1)', 'version' => 0], 'PATCH'));
        $this->assertBrowserRejected($this->browserWrite('/settings/general', [...$input, 'version' => 1, 'stripe_secret' => 'forged'], 'PATCH'));
        $this->browserLogin($this->makeUser('support'), 'support');
        $this->browserPage('/settings/general')->assertForbidden();
        $this->browserWrite('/settings/general', [...$input, 'version' => 1], 'PATCH')->assertForbidden();
    }

    public function testStaffPriceComparisonAndStripeGuideExposeOnlyPublicSetupInformation(): void
    {
        $this->app->mergeConfig(['billing' => [
            'stripe_secret' => 'sk_test_private_fixture',
            'webhook_secrets' => ['whsec_private_fixture'],
            'portal_configuration' => 'bpc_private_fixture',
            'automatic_tax' => true,
            'plans' => ['artist_starter' => ['price_id' => 'price_private_fixture']],
        ]]);

        $this->browserLogin($this->makeUser('pricing-admin'));
        $guide = $this->browserPage('/settings/integrations')->assertOk();
        $guide->assertJsonPath('props.stripe.endpoint', 'http://localhost/api/v1/webhooks/stripe')
            ->assertJsonPath('props.stripe.api_version', '2024-06-20')
            ->assertJsonPath('props.stripe.events.Invoices.0', 'invoice.paid')
            ->assertJsonPath('props.stripe.automatic_tax', true)
            ->assertJsonPath('props.stripe.portal_configured', true);
        $this->assertFalse(str_contains($guide->content(), 'private_fixture'));

        foreach (['/settings/catalog', '/billing/subscriptions'] as $path) {
            $page = $this->browserPage($path)->assertOk();
            $plans = $page->json('props.pricing.plans');
            $this->assertSame(['Artist Starter', 'Artist Pro', 'Artist Spotlight'], array_column($plans, 'name'));
            $this->assertSame([499, 1999, 9900], array_column($plans, 'monthly_cents'));
            $this->assertSame([4900, 19900, 99000], array_column($plans, 'yearly_cents'));
            $this->assertSame([true, true, false], array_column($plans, 'monthly_checkout'));
            $this->assertTrue($plans[0]['price_configured']);
            $this->assertFalse(str_contains($page->content(), 'private_fixture'));
        }

        $offers = $this->getJson('/api/v1/plans')->assertOk()->json('data.offers');
        $this->assertSame(['free', 'artist_starter', 'artist_pro'], array_column($offers, 'code'));
        $this->assertSame([null, 'month', 'month'], array_column($offers, 'interval'));
    }

    public function testTaxonomyArchivesNewSelectionsAndPreservesExistingReferences(): void
    {
        $this->browserLogin($this->makeUser('admin'));
        $count = DB::table('artist_genres')->count();
        $input = ['name' => 'Fixture Genre', 'slug' => 'fixture-genre', 'position' => 1, 'archived' => false, 'version' => 0];
        $this->browserWrite('/settings/taxonomy/genres', $input)->assertStatus(303);
        $genre = (int) DB::table('artist_genres')->where('slug', 'fixture-genre')->value('id');
        $this->getJson('/api/v1/artist-taxonomy')->assertOk()->assertJsonCount($count + 1, 'data.genres');
        $this->assertBrowserRejected($this->browserWrite('/settings/taxonomy/genres', [...$input, 'slug' => 'another', 'name' => 'FIXTURE GENRE']));
        $this->browserWrite('/settings/taxonomy/genres/' . $genre, [...$input, 'version' => 1, 'archived' => true], 'PATCH')->assertStatus(303);
        $this->getJson('/api/v1/artist-taxonomy')->assertOk()->assertJsonCount($count, 'data.genres');
        \App\Models\ArtistGenre::assertAssignable([$genre], [$genre]);
        $this->assertThrows(\Spark\Exceptions\Http\ResponseException::class, fn () => \App\Models\ArtistGenre::assertAssignable([$genre]));
        $this->assertDatabaseHas('artist_genres', ['id' => $genre, 'archived' => true, 'version' => 2]);
    }

    public function testExportsArePrivateDurableScopedExpiredAndFormulaSafe(): void
    {
        $this->browserLogin($actor = $this->makeUser('analyst'), 'analyst');
        $input = ['type' => 'dashboard', 'period' => 'month', 'idempotency_key' => 'dashboard-report-first'];
        $this->browserWrite('/exports', $input)->assertStatus(303);
        $this->browserWrite('/exports', $input)->assertStatus(303);
        $this->assertDatabaseCount('staff_exports', 1);
        $this->assertDatabaseCount('staff_operations', 1);
        $id = (int) DB::table('staff_exports')->value('id');
        $this->get('/exports/' . $id . '/download')->assertStatus(410);
        Operations::run((int) DB::table('staff_operations')->value('id'));
        $this->assertDatabaseHas('staff_exports', ['id' => $id, 'status' => 'ready']);
        $this->get('/exports/' . $id . '/download')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->browserPage('/exports/' . $id . '/download')->assertStatus(400);
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.export.downloaded', 'actor_id' => $actor->id]);
        $this->browserWrite('/exports', [...$input, 'type' => 'finance', 'idempotency_key' => 'forbidden-finance-report'])->assertForbidden();
        $this->browserLogin($this->makeUser('otheranalyst'), 'analyst');
        $this->browserPage('/exports/' . $id)->assertNotFound();
        $this->get('/exports/' . $id . '/download')->assertNotFound();
        $this->browserLogin($actor, 'analyst');
        DB::table('staff_exports')->where('id', $id)->update(['expires_at' => Sessions::timestamp(-1)]);
        $this->get('/exports/' . $id . '/download')->assertStatus(410);
        Exports::maintain();
        $this->assertFalse(Storage::disk('local')->exists(Exports::path($id)));
        $this->assertDatabaseHas('staff_exports', ['id' => $id, 'status' => 'expired']);
        foreach (['=CMD()', '+1', '-1', '@SUM(1)', "\t=1", '  =2'] as $cell) {
            $this->assertTrue(str_starts_with(Exports::cell($cell), "'"));
        }
    }

    public function testTaxonomyNormalizesUnicodeCaseAndSurroundingWhitespace(): void
    {
        $this->browserLogin($this->makeUser('admin'));
        $input = ['name' => "\u{00A0}ÉLECTRO\u{00A0}", 'slug' => 'electro-fixture', 'position' => 1, 'archived' => false, 'version' => 0];
        $this->browserWrite('/settings/taxonomy/genres', $input)->assertStatus(303);
        $this->assertDatabaseHas('artist_genres', ['slug' => 'electro-fixture', 'name' => 'ÉLECTRO']);
        $this->assertBrowserRejected($this->browserWrite('/settings/taxonomy/genres', [...$input, 'name' => 'électro', 'slug' => 'electro-duplicate']));
        $this->assertDatabaseMissing('artist_genres', ['slug' => 'electro-duplicate']);
    }

    public function testExportWorkerCancelsAfterSourcePermissionRevocationAndPrivacyRemovesAccess(): void
    {
        $this->browserLogin($actor = $this->makeUser('analyst'), 'analyst');
        $this->browserWrite('/exports', ['type' => 'dashboard', 'period' => 'month', 'idempotency_key' => 'export-revocation-one'])->assertStatus(303);
        $id = (int) DB::table('staff_exports')->value('id');
        DB::table('staff_accounts')->where('user_id', $actor->id)->update(['role' => 'support', 'version' => 2]);
        Operations::run((int) DB::table('staff_operations')->value('id'));
        $this->assertDatabaseHas('staff_exports', ['id' => $id, 'status' => 'cancelled']);
        $this->assertFalse(Storage::disk('local')->exists(Exports::path($id)));
        Exports::deleting($actor->id);
        Exports::maintain();
        $this->assertDatabaseHas('staff_exports', ['id' => $id, 'status' => 'expired']);
    }

    public function testExportIdempotencyRejectsChangedSourcePresetAndCustomDates(): void
    {
        $this->browserLogin($this->makeUser('admin'));
        $today = gmdate('Y-m-d');
        $yesterday = gmdate('Y-m-d', time() - 86400);
        $input = ['type' => 'dashboard', 'period' => 'custom', 'from' => $today, 'to' => $today, 'idempotency_key' => 'fixed-custom-report'];
        $this->browserWrite('/exports', $input)->assertStatus(303);
        foreach ([['type' => 'finance'], ['period' => 'month'], ['from' => $yesterday]] as $change) {
            $this->assertBrowserRejected($this->browserWrite('/exports', [...$input, ...$change]));
        }
        $this->assertDatabaseCount('staff_exports', 1);
        $this->assertDatabaseCount('staff_operations', 1);
    }

    public function testCompletedExportLosesAccessAfterRoleChange(): void
    {
        $this->browserLogin($actor = $this->makeUser('analyst'), 'analyst');
        $this->browserWrite('/exports', ['type' => 'dashboard', 'period' => 'month', 'idempotency_key' => 'completed-report-revocation'])->assertStatus(303);
        Operations::run((int) DB::table('staff_operations')->value('id'));
        $id = (int) DB::table('staff_exports')->value('id');
        $this->assertDatabaseHas('staff_exports', ['id' => $id, 'status' => 'ready']);
        DB::table('staff_accounts')->where('user_id', $actor->id)->update(['role' => 'support', 'version' => 2]);
        $this->browserLogin($actor, 'support');
        $this->get('/exports/' . $id . '/download')->assertForbidden();
        $this->browserPage('/exports')->assertForbidden();
    }

    public function testOperationalExportsRetainDomainBacklogWhenQueueMetadataIsUnavailable(): void
    {
        $owner = $this->makeUser('owner');
        $this->makeVideo($owner, ['status' => 'failed', 'processing_state' => 'failed']);
        $this->makeVideo($owner, ['status' => 'deleted', 'processing_state' => 'failed']);
        $this->app->mergeConfig(['queue' => ['default' => 'file']]);
        $this->browserLogin($this->makeUser('admin'));
        $this->browserWrite('/exports', ['type' => 'operations', 'period' => 'month', 'idempotency_key' => 'operations-file-queue-report'])->assertStatus(303);
        Operations::run((int) DB::table('staff_operations')->value('id'));
        $id = (int) DB::table('staff_exports')->value('id');
        $csv = $this->get('/exports/' . $id . '/download')->assertOk()->content();
        $this->assertTrue(str_contains($csv, 'queue_metadata,availability,unavailable'));
        $this->assertTrue(str_contains($csv, 'domain_backlog,"Failed videos",1,'));
        $this->assertTrue(str_contains($csv, 'domain_oldest,"Failed videos",'));
        $this->assertFalse(str_contains($csv, 'queue:default'));
    }

    public function testMembershipEnableRequiresRetainedValidFactorAndLeavesSessionsRevoked(): void
    {
        $this->browserLogin($member = $this->makeUser('member'), 'support');
        $account = DB::table('staff_accounts')->where('user_id', $member->id)->first();
        $this->browserLogin($this->makeUser('admin'));
        $input = ['action' => 'disable', 'role' => 'support', 'version' => 1, 'reason' => 'Temporary staff access suspension'];
        $this->browserWrite('/settings/team/' . $account->id, $input, 'PATCH')->assertStatus(303);
        $this->browserWrite('/settings/team/' . $account->id, [...$input, 'action' => 'enable', 'version' => 2], 'PATCH')->assertStatus(303);
        $this->assertDatabaseHas('staff_accounts', ['id' => $account->id, 'enabled' => true, 'version' => 3, 'mfa_secret' => $account->mfa_secret]);
        $this->assertFalse(DB::table('staff_sessions')->where('user_id', $member->id)->whereNull('revoked_at')->exists());
        $this->assertBrowserRejected($this->browserWrite('/settings/team/' . $account->id, [...$input, 'action' => 'enable', 'version' => 2], 'PATCH'));
        DB::table('staff_accounts')->where('id', $account->id)->update(['enabled' => false, 'mfa_secret' => 'invalid-ciphertext']);
        $this->assertBrowserRejected($this->browserWrite('/settings/team/' . $account->id, [...$input, 'action' => 'enable', 'version' => 3], 'PATCH'));
        $this->browserPage('/settings/preferences')->assertOk()->assertJsonPath('component', 'settings/preferences');
        $this->browserPage('/settings/team/' . $account->id)->assertOk();
    }

    public function testBrowserPasswordRecoveryIsOneUseAndPreservesMembershipAndMfa(): void
    {
        $this->browserLogin($actor = $this->makeUser('recovering'), 'support');
        $member = DB::table('staff_accounts')->where('user_id', $actor->id)->first();
        $token = str_repeat('a', 64);
        DB::table('password_reset_tokens')->insert(['email' => $actor->email, 'token' => \Spark\Facades\Hash::password($token), 'created_at' => now()]);
        \Spark\Facades\Session::flush();
        $this->browserPage('/forgot-password')->assertOk()->assertJsonPath('props.auth', null);
        foreach (['/forgot-password', '/reset-password'] as $path) {
            $this->post($path, [], $this->browserHeaders())->assertStatus(419);
        }
        $this->browserWrite('/forgot-password', ['email' => 'unknown@example.test'])->assertStatus(303);
        $this->browserPage('/reset-password?email=' . urlencode($actor->email) . '&token=' . $token)->assertOk();
        $input = ['email' => $actor->email, 'token' => $token, 'password' => 'NewRecoveryPassword123!', 'password_confirmation' => 'NewRecoveryPassword123!'];
        $this->browserWrite('/reset-password', $input)->assertStatus(303);
        $this->assertTrue(\Spark\Facades\Hash::password($input['password'], \App\Models\User::find($actor->id)->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $actor->email]);
        $this->assertFalse(DB::table('staff_sessions')->where('user_id', $actor->id)->whereNull('revoked_at')->exists());
        $this->assertDatabaseHas('staff_accounts', ['id' => $member->id, 'role' => 'support', 'version' => 1, 'mfa_secret' => $member->mfa_secret]);
        $this->browserPage('/login')->assertOk();
        $this->assertBrowserRejected($this->browserWrite('/reset-password', $input));
        $this->browserWrite('/login', ['email' => $actor->email, 'password' => $input['password']])->assertStatus(303);
        $this->assertSame(null, session('staff_user_id'));
        $this->browserPage('/challenge')->assertOk();
    }
}
