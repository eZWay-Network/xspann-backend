<?php

namespace Tests\Support;

use App\Models\{Audio, Video};
use Spark\Database\Migration;
use Spark\Facades\Schema;

trait ReleaseUpgrades
{
    public function testStaffFinanceForwardUpgradePreservesPaidHistoryAndMembership(): void
    {
        $this->billingSetup();
        $owner = $this->makeUser('upgradebuyer');
        $this->makeArtist($owner);
        $this->asUser($owner);
        $attempt = $this->checkout();
        $this->event('invoice.paid', $this->stripe->complete($attempt->session_id));
        $before = query('billing_invoices')->first();
        $secret = \Spark\Facades\Hash::encrypt('FORWARD-MFA-FIXTURE');
        query('staff_accounts')->insert(['user_id' => $owner->id, 'role' => 'finance', 'enabled' => true,
            'mfa_secret' => $secret, 'last_counter' => 123, 'version' => 7, 'created_at' => now(), 'updated_at' => now()]);
        $migration = require dirname(__DIR__, 2) . '/database/migrations/migration_2026_11_01_160000_staff_finance_operations.php';
        $migration->down();
        $migration->up();
        $migration->up();
        $this->assertSame($before->amount_paid, query('billing_invoices')->where('id', $before->id)->value('amount_paid'));
        $this->assertDatabaseHas('staff_accounts', ['user_id' => $owner->id, 'mfa_secret' => $secret, 'last_counter' => 123, 'version' => 7]);
        $this->assertDatabaseCount('billing_paid_periods', 1);
        $this->assertTrue(query('artist_genres')->where('archived', false)->where('version', 1)->exists());
        $migration->down();
        $migration->up();
        $this->assertSame($before->invoice_id, query('billing_invoices')->where('id', $before->id)->value('invoice_id'));

        $member = query('staff_accounts')->where('user_id', $owner->id)->first();
        $context = new \App\Services\Staff\Context($owner, $member, (object) []);
        $range = \App\Queries\Staff\Overview::range([]);
        $report = \App\Queries\Staff\Finance::read($context, $range);
        $this->assertSame(1999, $report['invoices'][0]['amount_paid']);
        $this->assertSame(1, $report['paid_access'][0]['accounts']);
        $this->assertSame('available', \App\Queries\Staff\OperationalHealth::read()['queue_availability']);
        $export = (int) query('staff_exports')->insert([
            'actor_id' => $owner->id, 'membership_version' => 7, 'type' => 'finance', 'environment' => 'test',
            'filters' => json_encode($range), 'idempotency_hash' => hash('sha256', 'upgrade-export'),
            'status' => 'queued', 'expires_at' => \App\Services\Staff\Sessions::timestamp(86400),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(['completed', 'export_ready'], \App\Services\Staff\Exports::generate($export, $owner->id, $member));
        \App\Services\Staff\Exports::deleting($owner->id);
        \App\Services\Staff\Exports::maintain();
        $this->assertDatabaseHas('staff_exports', ['id' => $export, 'status' => 'expired']);
        $this->assertFalse(\Spark\Facades\Storage::disk('local')->exists(\App\Services\Staff\Exports::path($export)));
    }

    public function testStaffCommunityCutoverPreservesEvidenceAndRetiresOnlyLegacyGrants(): void
    {
        $owner = $this->makeUser('cutoverowner');
        $reporter = $this->makeUser('cutoverreporter');
        $report = \App\Services\Moderation::report($reporter, ['reported_user_id' => $owner->id, 'reason' => 'Preserved report context']);
        $before = query('reports')->where('id', $report->id)->first();
        $migration = require dirname(__DIR__, 2) . '/database/migrations/migration_2026_11_01_150000_staff_community_workflows.php';
        $migration->down();
        foreach (['legacy' => null, 'browser' => 1] as $name => $version) {
            query('staff_sessions')->insert([
                'user_id' => $reporter->id, 'session_hash' => hash('sha256', 'cutover-' . $name),
                'membership_version' => $version, 'expires_at' => now()->addMinutes(15), 'created_at' => now(),
            ]);
        }
        $migration->up();
        $migration->up();
        $this->assertSame($before->evidence, query('reports')->where('id', $report->id)->value('evidence'));
        $this->assertDatabaseHas('reports', ['id' => $report->id, 'source' => 'customer', 'initiator_id' => null]);
        $this->assertDatabaseCount('staff_sessions', 1);
        $this->assertDatabaseHas('staff_sessions', ['session_hash' => hash('sha256', 'cutover-browser'), 'membership_version' => 1]);
        query('reports')->where('id', $report->id)->update(['source' => 'staff', 'initiator_id' => $reporter->id]);
        $this->assertThrows(\PDOException::class, fn () => query('reports')->where('id', $report->id)->update(['initiator_id' => 999999]));
        $migration->down();
        $migration->up();
        $this->assertSame($before->evidence, query('reports')->where('id', $report->id)->value('evidence'));
    }

    public function testStaffBrowserMigrationPreservesMembershipAndLegacyHistory(): void
    {
        $user = $this->makeUser('staff');
        $secret = \Spark\Facades\Hash::encrypt('MIGRATION-FIXTURE');
        query('staff_accounts')->insert([
            'user_id' => $user->id,
            'role' => 'admin',
            'enabled' => true,
            'mfa_secret' => $secret,
            'last_counter' => 123,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['legacy' => null, 'browser' => 1] as $name => $version) {
            query('staff_sessions')->insert([
                'user_id' => $user->id,
                'session_hash' => hash('sha256', $name),
                'membership_version' => $version,
                'expires_at' => now()->addMinutes(15),
                'created_at' => now(),
            ]);
        }

        $migration = require dirname(__DIR__, 2) . '/database/migrations/migration_2026_11_01_140000_staff_browser_foundation.php';
        $migration->down();
        $this->assertDatabaseCount('staff_sessions', 1);
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('staff_accounts', [
            'user_id' => $user->id,
            'role' => 'admin',
            'mfa_secret' => $secret,
            'last_counter' => 123,
            'version' => 1,
        ]);
        $this->assertDatabaseHas('staff_sessions', [
            'session_hash' => hash('sha256', 'legacy'),
            'membership_version' => null,
        ]);
    }

    public function testBoostReportForwardMigrationPreservesExistingReportsAndEvidence(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/migration_2026_11_01_130000_boost_reports.php';
        $migration->down();
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $video = $this->makeVideo($owner);
        $fan = $this->makeUser('reporter');
        $legacy = $this->asUser($fan)->postJson('/api/v1/reports', ['video_id' => $video->id, 'reason' => 'Existing report'])->assertCreated()->json('data.id');
        $before = query('reports')->where('id', $legacy)->first();
        $migration->up();
        $migration->up();
        $after = query('reports')->where('id', $legacy)->first();
        $this->assertSame($before->evidence, $after->evidence);
        $this->assertSame($video->id, (int) $after->video_id);
        $this->assertSame(null, $after->boost_id);

        $boost = \App\Services\Promotions\Boosts::create($owner, $this->boostInput($video->id));
        $report = \App\Models\Report::create([
            'user_id' => $fan->id,
            'subject_user_id' => $owner->id,
            'boost_id' => $boost->id,
            'target_type' => 'boost',
            'target_id' => $boost->id,
            'reason' => 'Retained campaign evidence',
            'evidence' => json_encode(['type' => 'boost', 'id' => $boost->id]),
        ]);
        $this->assertThrows(\RuntimeException::class, fn() => $migration->down());
        query('promotion_boosts')->where('id', $boost->id)->delete();
        $this->assertSame(null, $report->refresh()->boost_id);
        $this->assertSame($boost->id, (int) $report->target_id);
        $this->assertSame(['type' => 'boost', 'id' => $boost->id], json_decode($report->evidence, true));
        $this->assertThrows(\PDOException::class, fn() => query('reports')->where('id', $legacy)->update(['boost_id' => 999999]));
        $report->remove();
        $migration->down();
        $migration->up();
        $this->assertSame($before->evidence, query('reports')->where('id', $legacy)->value('evidence'));
    }

    protected function emptyReleaseDatabase(): void
    {
        $paths = glob(dirname(__DIR__, 2) . '/database/migrations/migration_*.php');
        foreach (array_reverse($paths) as $path) {
            (require $path)->down();
        }
        Schema::dropIfExists('migrations');
    }

    public function testMigrationRunnerUpgradesPopulatedFrozenBaselineWithoutResetOrDuplicateGrants(): void
    {
        $this->emptyReleaseDatabase();
        $paths = glob(dirname(__DIR__, 2) . '/database/migrations/migration_*.php');
        $directory = $this->storagePath . '/upgrade-migrations';
        mkdir($directory);
        foreach (array_slice($paths, 0, 6) as $path) {
            copy($path, $directory . '/' . basename($path));
        }
        $runner = new Migration($directory);
        $runner->up(['force' => true]);
        $this->assertDatabaseCount('migrations', 6);
        $owner = $this->makeUser('legacyartist');
        $fan = $this->makeUser('legacyfan');
        $video = query('videos')->insert([
            'user_id' => $owner->id,
            'storage_path' => "public:videos/$owner->id/legacy.mp4",
            'caption' => 'Preserved baseline content',
            'status' => 'published',
            'visibility' => 'public',
            'reuse_content' => true,
            'rights_version' => config('policies.rights_version'),
            'rights_confirmed_at' => now(),
            'created_at' => '2026-10-30 10:00:00',
        ]);
        $audio = query('audios')->insert([
            'user_id' => $owner->id,
            'source_video_id' => $video,
            'title' => 'Original baseline sound',
            'storage_path' => "public:sounds/$owner->id/legacy.m4a",
            'status' => 'ready',
        ]);
        query('videos')->where('id', $video)->update(['audio_id' => $audio]);
        query('follows')->insert(['follower_id' => $fan->id, 'following_id' => $owner->id]);
        query('notifications')->insert(['user_id' => $fan->id, 'type' => 'legacy', 'data' => '{"message":"Preserved notification"}']);

        // Apply each release step with the real migration ledger, retaining data from earlier steps.
        $stepTwo = $paths[6];
        copy($stepTwo, $directory . '/' . basename($stepTwo));
        $runner->up(['force' => true]);
        $artist = $this->makeArtist($owner);
        $playlist = query('artist_playlists')->insert(['artist_id' => $artist->id, 'title' => 'Preserved collection']);
        query('artist_playlist_items')->insert(['playlist_id' => $playlist, 'video_id' => $video, 'position' => 0]);
        $stepThree = $paths[7];
        copy($stepThree, $directory . '/' . basename($stepThree));
        $runner->up(['force' => true]);
        $this->assertDatabaseHas('videos', ['id' => $video, 'processing_state' => 'ready', 'publication_state' => 'published', 'publication_intent' => 'immediate', 'publish_at' => null]);

        // Insert a pre-communication billing history using the normal HTTP/provider boundary.
        $this->billingSetup();
        $this->asUser($owner);
        $attempt = $this->checkout(key: 'baseline-upgrade-checkout');
        $invoice = $this->stripe->complete($attempt->session_id);
        // Step 3 code now emits outbox hints, so reconcile after Step 4's forward schema arrives.
        foreach (array_slice($paths, 8) as $path) {
            copy($path, $directory . '/' . basename($path));
            $runner->up(['force' => true]);
        }
        $this->event('invoice.paid', $invoice);
        $runner->up(['force' => true]);
        $this->assertDatabaseCount('migrations', count($paths));
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('follows', 1);
        $this->assertDatabaseHas('notifications', ['user_id' => $fan->id, 'type' => 'legacy']);
        $this->assertDatabaseCount('artist_update_subscriptions', 0);
        $this->assertDatabaseHas('artist_playlist_items', ['playlist_id' => $playlist, 'video_id' => $video]);
        $this->assertSame('Preserved baseline content', Video::find($video)->caption);
        $this->assertSame($video, Audio::find($audio)->source_video_id);
        $this->assertSame(500, \App\Services\Promotions\Credits::balance($owner->id)['available_cents']);
        $this->assertSame(500, \App\Services\Promotions\Credits::balance($owner->id)['available_cents']);
        $this->assertSame(1, query('promotion_ledger')->where('kind', 'grant')->count());
        $this->assertThrows(\PDOException::class, fn() => query('artist_playlist_items')->insert(['playlist_id' => $playlist, 'video_id' => $video, 'position' => 1]));
        $this->assertThrows(\PDOException::class, fn() => query('artist_playlist_items')->insert(['playlist_id' => $playlist, 'video_id' => 999999, 'position' => 1]));
        if (db()->getDriver() === 'sqlite') {
            $this->assertSame([], db()->getPdo()->query('PRAGMA foreign_key_check')->fetchAll());
        } else {
            $this->assertSame(1, (int) db()->getPdo()->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn());
        }
    }
}
