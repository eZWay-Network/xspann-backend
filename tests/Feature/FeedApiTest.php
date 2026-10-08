<?php

namespace Tests\Feature;

use App\Models\{Block, Follow, VideoView};
use Tests\TestCase;

class FeedApiTest extends TestCase
{
    public function testUnwatchedRecentAndPopularComeBeforeWatchedVideos(): void
    {
        $creator = $this->makeUser('creator');
        $viewer = $this->makeUser('viewer');
        $popular = $this->makeVideo($creator, ['likes_count' => 50]);
        $older = $this->makeVideo($creator);
        $newer = $this->makeVideo($creator);
        $watched = $this->makeVideo($creator, ['likes_count' => 500]);
        VideoView::create(['user_id' => $viewer->id, 'video_id' => $watched->id]);
        $this->asUser($viewer);

        $response = $this->cursorPage(limit: 3);
        $this->assertSame([$newer->id, $older->id, $popular->id], array_column($response['data'], 'id'));
        $this->assertTrue($response['meta']['has_more']);
        $last = $this->cursorPage($response['meta']['next_cursor'], 3);
        $this->assertSame([$watched->id], array_column($last['data'], 'id'));
        $this->assertFalse($last['meta']['has_more']);
        $this->assertSame(null, $last['meta']['next_cursor']);

        $this->getJson('/api/v1/feed?limit=4')->assertOk()
            ->assertJsonPath('meta.total', 4)
            ->assertJsonPath('data.3.id', $watched->id);
    }

    public function testCursorDoesNotSkipOrRepeatWhenViewsScoresAndUploadsChange(): void
    {
        $creator = $this->makeUser('creator');
        $viewer = $this->makeUser('viewer');
        $popular = $this->makeVideo($creator, ['likes_count' => 2]);
        $middle = $this->makeVideo($creator);
        $latest = $this->makeVideo($creator);
        $this->asUser($viewer);
        $first = $this->cursorPage(limit: 1);
        $this->assertSame($latest->id, $first['data'][0]['id']);
        $this->postJson('/api/v1/videos/' . $latest->id . '/view')->assertStatus(201);
        $middle->update(['likes_count' => 1000]);
        $newUpload = $this->makeVideo($creator);

        $second = $this->cursorPage($first['meta']['next_cursor'], 1);
        $third = $this->cursorPage($second['meta']['next_cursor'], 1);
        $this->assertSame($middle->id, $second['data'][0]['id']);
        $this->assertSame($popular->id, $third['data'][0]['id']);
        $this->assertFalse($third['meta']['has_more']);
        $retry = $this->cursorPage($first['meta']['next_cursor'], 1);
        $this->assertSame($second['data'], $retry['data']);

        $refreshed = $this->cursorPage(limit: 4);
        $this->assertSame($newUpload->id, $refreshed['data'][0]['id']);
        $this->assertSame($latest->id, $refreshed['data'][3]['id']);
    }

    public function testPopularOnlyAndLatestOnlyFeedsTerminateWithoutDuplicates(): void
    {
        $creator = $this->makeUser();
        for ($index = 0; $index < 35; $index++) {
            $this->makeVideo($creator, ['likes_count' => ($index * 7) % 11 + 1]);
        }

        $ids = $this->allIds(limit: 7);
        $this->assertCount(35, $ids);
        $this->assertCount(35, array_unique($ids));
        query('videos')->where('user_id', $creator->id)->update(['likes_count' => 0]);
        $this->assertSame(range(35, 1), $this->allIds(limit: 7));
    }

    public function testFollowingAndVisibilityAreRecheckedOnEveryPage(): void
    {
        $viewer = $this->makeUser('viewer');
        $creator = $this->makeUser('creator');
        $other = $this->makeUser('other');
        Follow::create(['follower_id' => $viewer->id, 'following_id' => $creator->id]);
        $hiddenLater = $this->makeVideo($creator, ['likes_count' => 10]);
        $this->makeVideo($creator);
        $latest = $this->makeVideo($creator, ['visibility' => 'followers']);
        $this->makeVideo($creator, ['visibility' => 'private']);
        $this->makeVideo($creator, ['status' => 'processing']);
        $this->makeVideo($other);
        $this->asUser($viewer);
        $page = $this->cursorPage(limit: 1, following: true);
        $this->assertSame($latest->id, $page['data'][0]['id']);
        $hiddenLater->update(['visibility' => 'private']);
        Block::create(['blocker_id' => $creator->id, 'blocked_id' => $viewer->id]);
        $next = $this->cursorPage($page['meta']['next_cursor'], 10, true);
        $this->assertSame([], $next['data']);
        $this->assertFalse($next['meta']['has_more']);
    }

    public function testGuestsUseOnlyTheirAnonymousHistoryAndEmptyFeedsTerminate(): void
    {
        $empty = $this->cursorPage();
        $this->assertSame([], $empty['data']);
        $this->assertSame(null, $empty['meta']['next_cursor']);
        $creator = $this->makeUser();
        $older = $this->makeVideo($creator);
        $middle = $this->makeVideo($creator);
        $newer = $this->makeVideo($creator);
        $latest = $this->makeVideo($creator);
        $this->withHeaders(['User-Agent' => 'feed-test']);
        $this->postJson('/api/v1/videos/' . $latest->id . '/view')->assertStatus(201);
        $this->assertSame([$newer->id, $middle->id, $older->id, $latest->id], array_column($this->cursorPage()['data'], 'id'));
        $this->withHeaders(['User-Agent' => 'another-device']);
        $this->assertSame([$latest->id, $newer->id, $middle->id, $older->id], array_column($this->cursorPage()['data'], 'id'));
        $this->asUser($creator);
        $this->assertSame([$latest->id, $newer->id, $middle->id, $older->id], array_column($this->cursorPage()['data'], 'id'));
    }

    public function testCursorsRejectTamperingWrongAccountWrongFeedAndExpiry(): void
    {
        $creator = $this->makeUser();
        $viewer = $this->makeUser('viewer');
        $this->makeVideo($creator);
        $this->makeVideo($creator);
        $this->asUser($creator);
        $cursor = $this->cursorPage(limit: 1)['meta']['next_cursor'];
        $this->getJson('/api/v1/feed?cursor=invalid')->assertStatus(422);
        $this->getJson('/api/v1/feed?cursor=' . urlencode(substr($cursor, 0, -8) . 'tampered'))->assertStatus(422);
        $this->getJson('/api/v1/feed/following?cursor=' . urlencode($cursor))->assertStatus(422);
        $expired = decrypt($cursor);
        $expired['expires'] = time() - 1;
        $this->getJson('/api/v1/feed?cursor=' . urlencode(encrypt($expired)))->assertStatus(410);
        $this->asUser($viewer);
        $this->getJson('/api/v1/feed?cursor=' . urlencode($cursor))->assertStatus(422);
        foreach (['limit=bad', 'limit[]=1', 'pagination=unknown', 'cursor[]=x', 'page=-1'] as $query) {
            $this->getJson('/api/v1/feed?' . $query)->assertStatus(422);
        }
    }

    public function testGuestViewsAreRecordedSeparatelyAfterSigningOut(): void
    {
        $user = $this->makeUser();
        $unwatched = $this->makeVideo($user);
        $watched = $this->makeVideo($user);
        $this->asUser($user)->postJson('/api/v1/videos/' . $watched->id . '/view')->assertStatus(201);
        $this->flushHeaders();
        $this->postJson('/api/v1/videos/' . $watched->id . '/view')->assertStatus(201);
        $this->assertDatabaseHas('video_views', ['video_id' => $watched->id, 'user_id' => null]);
        $this->assertSame([$unwatched->id, $watched->id], array_column($this->cursorPage()['data'], 'id'));
    }

    public function testCursorQueriesAreBoundedAndDoNotCountEveryVideo(): void
    {
        $creator = $this->makeUser();
        for ($index = 0; $index < 20; $index++) {
            $this->makeVideo($creator);
        }
        $this->app->mergeConfig(['app' => ['debug' => true]]);
        $queries = [];
        event()->addListener('app:db.queryExecuted', function ($event) use (&$queries): void {
            $queries[] = $event;
        });
        $this->cursorPage(limit: 1);
        $small = count($queries);
        $queries = [];
        $this->cursorPage(limit: 12);
        $this->assertSame($small, count($queries));
        $this->assertTrue($small <= 12);

        foreach ($queries as $query) {
            $sql = strtoupper($query);
            $this->assertFalse(str_starts_with($sql, 'SELECT COUNT(*) FROM ('));
            $this->assertFalse(str_contains($sql, ' OFFSET '));
        }
    }

    public function testAllWatchedVideosRemainAvailableAsFallback(): void
    {
        $user = $this->makeUser();
        for ($index = 0; $index < 3; $index++) {
            $video = $this->makeVideo($user);
            VideoView::create(['user_id' => $user->id, 'video_id' => $video->id]);
        }
        $this->asUser($user);
        $this->assertSame([3, 2, 1], $this->allIds(limit: 1));
    }

    public function testFeedIndexMigrationCanRollBackWithoutRemovingData(): void
    {
        $this->makeVideo($this->makeUser());
        $migration = require dirname(__DIR__, 2) . '/database/migrations/migration_2026_10_08_090000_feed_indexes.php';
        $names = ['idx_views_user_history', 'idx_views_guest_history', 'idx_videos_feed'];
        $this->assertSame(3, query('sqlite_master')->whereIn('name', $names)->count());
        $migration->down();
        $this->assertSame(0, query('sqlite_master')->whereIn('name', $names)->count());
        $migration->up();
        $this->assertSame(3, query('sqlite_master')->whereIn('name', $names)->count());
        $this->assertDatabaseCount('videos', 1);
    }

    public function testDocsExportBothFeedPaginationContracts(): void
    {
        $html = $this->get('/')->assertOk()->content();
        preg_match('/<script id="reference-data" type="application\/json">(.*?)<\/script>/s', $html, $matches);
        $docs = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        foreach ($docs['groups']['Feed'] as $entry) {
            $this->assertSame(['pagination', 'cursor', 'page', 'limit'], array_column($entry['parameters'], 'name'));
            $this->assertTrue($entry['alternate']['meta']['has_more']);
            $this->assertSame('<opaque-feed-cursor>', $entry['alternate']['meta']['next_cursor']);
            $this->assertArrayHasKey('total', $entry['response']['meta']);
            $this->assertArrayHasKey('410', $entry['errors']);
        }
    }

    private function cursorPage(?string $cursor = null, int $limit = 10, bool $following = false): array
    {
        return $this->getJson('/api/v1/feed' . ($following ? '/following' : '') . '?' . http_build_query([
            'pagination' => 'cursor',
            'limit' => $limit,
            ...($cursor === null ? [] : ['cursor' => $cursor]),
        ]))->assertOk()->json();
    }

    private function allIds(int $limit): array
    {
        $cursor = null;
        $ids = [];
        for ($page = 0; $page < 20; $page++) {
            $response = $this->cursorPage($cursor, $limit);
            array_push($ids, ...array_column($response['data'], 'id'));
            $cursor = $response['meta']['next_cursor'];
            if ($cursor === null) {
                return $ids;
            }
        }

        $this->fail('The feed did not terminate.');
    }
}
