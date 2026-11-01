<?php

namespace Tests\Support;

use App\Models\{Audio, Video};
use Spark\Database\Migration;
use Spark\Facades\Schema;

trait ReleaseUpgrades
{
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
            'user_id' => $owner->id, 'storage_path' => "public:videos/$owner->id/legacy.mp4", 'caption' => 'Preserved baseline content',
            'status' => 'published', 'visibility' => 'public', 'reuse_content' => true,
            'rights_version' => config('policies.rights_version'), 'rights_confirmed_at' => now(), 'created_at' => '2026-10-30 10:00:00',
        ]);
        $audio = query('audios')->insert([
            'user_id' => $owner->id, 'source_video_id' => $video, 'title' => 'Original baseline sound',
            'storage_path' => "public:sounds/$owner->id/legacy.m4a", 'status' => 'ready',
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
