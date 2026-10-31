<?php

namespace Tests\Feature;

use Spark\Database\Schema\Blueprint;
use Spark\Facades\Schema;
use Tests\TestCase;

class ReleaseMigrationTest extends TestCase
{
    public function testPopulatedForwardUpgradePreservesOldIntentAndRestrictionsWithoutSchedulingMetadata(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/migration_2026_10_31_150000_billing_and_releases.php';
        $migration->down();
        $owner = $this->makeUser();
        Schema::table('videos', function (Blueprint $table): void {
            $table->timestamp('scheduled_at')->nullable();
        });
        foreach (['published', 'processing', 'failed', 'deleted', 'source_unavailable'] as $index => $status) {
            query('videos')->insert([
                'user_id' => $owner->id, 'storage_path' => "public:videos/1/legacy$index.mp4", 'status' => $status,
                'moderation_status' => $status === 'published' ? 'restricted' : 'approved', 'visibility' => 'private',
                'scheduled_at' => '2099-01-01 00:00:00', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            ]);
        }
        $migration->up();
        foreach (query('videos')->orderBy('id')->get() as $video) {
            $this->assertSame('immediate', $video->publication_intent);
            $this->assertSame(null, $video->publish_at);
            $this->assertSame(1, $video->version);
            $this->assertSame('private', $video->visibility);
            $this->assertSame('2099-01-01 00:00:00', $video->scheduled_at);
            $this->assertSame($video->status === 'published' ? 'published' : 'draft', $video->publication_state);
            $this->assertSame(match ($video->status) { 'published' => 'ready', 'processing' => 'processing', default => 'failed' }, $video->processing_state);
        }
        $this->assertSame('restricted', query('videos')->where('status', 'published')->first()->moderation_status);
        $this->assertSame('2026-01-01 00:00:00', query('videos')->where('status', 'published')->first()->published_at);
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('videos', 5);
        $this->assertSame([], db()->getPdo()->query('PRAGMA foreign_key_check')->fetchAll());
    }
    public function testRollbackRefusesProcessingDraftsBeforeDroppingAnyBillingTables(): void
    {
        $owner = $this->makeUser();
        $this->makeVideo($owner, ['status' => 'processing', 'publication_intent' => 'draft']);
        $migration = require dirname(__DIR__, 2) . '/database/migrations/migration_2026_10_31_150000_billing_and_releases.php';
        try {
            $migration->down();
            $this->fail('Rollback accepted an unpublished private draft.');
        } catch (\RuntimeException $exception) {
            $this->assertTrue(str_contains($exception->getMessage(), 'Resolve draft/scheduled'));
        }
        $this->assertDatabaseCount('billing_checkouts', 0);
        $this->assertDatabaseCount('videos', 1);
    }

    public function testLegacyPublicationDatesAreConvertedFromTheConfiguredTimezone(): void
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/migration_2026_10_31_150000_billing_and_releases.php';
        $migration->down();
        $owner = $this->makeUser();
        query('videos')->insert(['user_id' => $owner->id, 'storage_path' => 'public:videos/1/old.mp4',
            'status' => 'published', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $this->app->mergeConfig(['app' => ['timezone' => 'Asia/Dhaka']]);
        $migration->up();
        $this->assertSame('2025-12-31 18:00:00', query('videos')->first()->published_at);
        $this->assertSame('2026-01-01 00:00:00', query('videos')->first()->created_at);
    }

}
