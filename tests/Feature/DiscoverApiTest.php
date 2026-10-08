<?php

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Follow;
use App\Models\Like;
use App\Models\Save;
use App\Models\Video;
use Tests\TestCase;

class DiscoverApiTest extends TestCase
{
    public function testGuestsOnlyDiscoverPublicPublishedVideosFromActiveAccounts(): void
    {
        $creator = $this->makeUser();
        $public = $this->makeVideo($creator);
        $this->makeVideo($creator, ['visibility' => 'private']);
        $this->makeVideo($creator, ['visibility' => 'followers']);

        foreach ([Video::STATUS_PROCESSING, Video::STATUS_FAILED, Video::STATUS_DELETED] as $status) {
            $this->makeVideo($creator, ['status' => $status]);
        }

        $inactive = $this->makeUser('inactive');
        $inactive->status = 'suspended';
        $inactive->save();
        $this->makeVideo($inactive);

        $this->getJson('/api/v1/discover')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $public->id)
            ->assertJsonPath('data.0.viewer.liked', false)
            ->assertJsonPath('meta.per_page', 18);
    }

    public function testSearchPreservesVisibilityBlocksAndAuthenticatedViewerState(): void
    {
        $viewer = $this->makeUser('viewer');
        $creator = $this->makeUser('creator');
        $blocked = $this->makeUser('blocked');
        $blocker = $this->makeUser('blocker');
        $public = $this->makeVideo($creator, ['caption' => 'Find me']);
        $this->makeVideo($creator, ['caption' => 'Find me', 'visibility' => 'followers']);
        $this->makeVideo($viewer, ['caption' => 'Find me', 'visibility' => 'private']);
        $this->makeVideo($blocked, ['caption' => 'Find me']);
        $this->makeVideo($blocker, ['caption' => 'Find me']);

        Follow::create(['follower_id' => $viewer->id, 'following_id' => $creator->id]);
        Like::create(['user_id' => $viewer->id, 'video_id' => $public->id]);
        Save::create(['user_id' => $viewer->id, 'video_id' => $public->id]);
        Block::create(['blocker_id' => $viewer->id, 'blocked_id' => $blocked->id]);
        Block::create(['blocker_id' => $blocker->id, 'blocked_id' => $viewer->id]);

        $this->asUser($viewer)->getJson('/api/v1/discover?q=find')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $public->id)
            ->assertJsonPath('data.0.viewer.liked', true)
            ->assertJsonPath('data.0.viewer.saved', true)
            ->assertJsonPath('data.0.viewer.following', true);
    }

    public function testVideoSearchFindsCaptionsHashtagsAndPartialCreatorNames(): void
    {
        $creator = $this->makeUser('city_explorer');
        $creator->name = 'Alex Morgan';
        $creator->save();
        $video = $this->makeVideo($creator, ['caption' => 'A walk in Dhaka #travel']);
        $this->makeVideo($this->makeUser('other'), ['caption' => 'Unrelated']);

        foreach (['DHAKA', '#travel', '@city_exp', 'morgan', '  walk in  '] as $search) {
            $this->getJson('/api/v1/discover?' . http_build_query(['q' => $search]))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $video->id);
        }

        $this->getJson('/api/v1/discover?q=missing')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    public function testSearchTreatsWildcardsAndSqlAsLiteralAndSupportsUnicode(): void
    {
        $creator = $this->makeUser('creator');
        $literal = $this->makeVideo($creator, ['caption' => "100%_fun! বাংলা ' OR 1=1 --"]);
        $this->makeVideo($creator, ['caption' => '100Xfun ordinary']);

        foreach (['%', '_', '!', 'বাংলা', "' OR 1=1 --"] as $search) {
            $this->getJson('/api/v1/discover?' . http_build_query(['q' => $search]))
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $literal->id);
        }

        $this->getJson('/api/v1/discover/people?q=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function testPopularAndLatestHaveStableOrderingAcrossPages(): void
    {
        $creator = $this->makeUser();
        $popular = $this->makeVideo($creator, ['likes_count' => 3]);
        $viewed = $this->makeVideo($creator, ['views_count' => 100]);
        $latest = $this->makeVideo($creator);
        query('videos')->where('user_id', $creator->id)->update(['created_at' => '2026-01-01 00:00:00']);

        $this->getJson('/api/v1/discover?limit=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $popular->id)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 3);
        $this->getJson('/api/v1/discover?limit=1&page=2')->assertJsonPath('data.0.id', $viewed->id);
        $this->getJson('/api/v1/discover?sort=latest&limit=1')->assertJsonPath('data.0.id', $latest->id);
        $this->getJson('/api/v1/discover?sort=latest&limit=1&page=2')->assertJsonPath('data.0.id', $viewed->id);
        // The installed Spark paginator clamps out-of-range requests to the last page.
        $this->getJson('/api/v1/discover?sort=latest&limit=1&page=4')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 3)
            ->assertJsonPath('data.0.id', $popular->id)
            ->assertJsonPath('links.next', null);
    }

    public function testPeopleSearchIncludesFollowedAccountsAndRanksExactUsernameFirst(): void
    {
        $viewer = $this->makeUser('viewer');
        $exact = $this->makeUser('alex');
        $partial = $this->makeUser('alexander');
        $named = $this->makeUser('other');
        $named->name = 'Alex Morgan';
        $named->save();
        Follow::create(['follower_id' => $viewer->id, 'following_id' => $exact->id]);

        $response = $this->asUser($viewer)->getJson('/api/v1/discover/people?q=%40alex&limit=1')
            ->assertOk()
            ->assertJsonPath('data.0.id', $exact->id)
            ->assertJsonPath('data.0.following', true)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 3);

        $this->assertFalse(array_key_exists('email', $response->json('data.0')));
        $this->assertFalse(array_key_exists('password', $response->json('data.0')));
        $second = $this->getJson('/api/v1/discover/people?q=alex&limit=1&page=2')->assertOk()->json('data.0.id');
        $third = $this->getJson('/api/v1/discover/people?q=alex&limit=1&page=3')->assertOk()->json('data.0.id');
        $this->assertSame([$named->id, $partial->id], [$second, $third]);
        $this->getJson('/api/v1/discover/people?q=viewer')->assertJsonPath('data.0.id', $viewer->id);
    }

    public function testPeopleSuggestionsExcludeSelfFollowingInactiveAndBothBlockDirections(): void
    {
        $viewer = $this->makeUser('viewer');
        $followed = $this->makeUser('followed');
        $blocked = $this->makeUser('blocked');
        $blocker = $this->makeUser('blocker');
        $suggested = $this->makeUser('suggested');
        $inactive = $this->makeUser('inactive');
        $inactive->status = 'suspended';
        $inactive->save();
        Follow::create(['follower_id' => $viewer->id, 'following_id' => $followed->id]);
        Block::create(['blocker_id' => $viewer->id, 'blocked_id' => $blocked->id]);
        Block::create(['blocker_id' => $blocker->id, 'blocked_id' => $viewer->id]);

        $this->asUser($viewer)->getJson('/api/v1/discover/people')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $suggested->id);

        foreach (['blocked', 'blocker', 'inactive'] as $search) {
            $this->getJson('/api/v1/discover/people?q=' . $search)->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function testGuestsCanBrowsePeopleAndEmptyQueriesBehaveLikeBrowsing(): void
    {
        $user = $this->makeUser();

        foreach (['', '?q=', '?q=%20%20'] as $query) {
            $this->getJson('/api/v1/discover/people' . $query)
                ->assertOk()
                ->assertJsonPath('data.0.id', $user->id)
                ->assertJsonPath('data.0.following', false);
            $this->getJson('/api/v1/discover' . $query)->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function testDiscoverRejectsMalformedQueryParameters(): void
    {
        foreach (['q' => ['array'], 'page' => 0, 'limit' => 101] as $field => $value) {
            foreach (['/discover', '/discover/people'] as $path) {
                $this->getJson('/api/v1' . $path . '?' . http_build_query([$field => $value]))
                    ->assertStatus(422)
                    ->assertJsonValidationErrors([$field]);
            }
        }

        $this->getJson('/api/v1/discover?' . http_build_query(['q' => str_repeat('x', 101)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
        $this->getJson('/api/v1/discover?sort=untrusted')->assertStatus(422)->assertJsonValidationErrors(['sort']);
        $this->getJson('/api/v1/discover?page=100001')->assertStatus(422)->assertJsonValidationErrors(['page']);
        $this->getJson('/api/v1/discover?limit=100')->assertOk()->assertJsonPath('meta.per_page', 100);
    }

    public function testSearchPaginationDoesNotAddQueriesForEachResult(): void
    {
        $viewer = $this->makeUser('viewer');

        for ($index = 0; $index < 12; $index++) {
            $this->makeVideo($this->makeUser('author' . $index), ['caption' => 'A matching video']);
        }

        $this->asUser($viewer)->getJson('/api/v1/discover')->assertOk();
        $this->app->mergeConfig(['app' => ['debug' => true]]);
        $queries = 0;
        event()->addListener('app:db.queryExecuted', function () use (&$queries): void {
            $queries++;
        });

        foreach (['/discover?q=matching', '/discover/people?q=author'] as $path) {
            $queries = 0;
            $this->getJson('/api/v1' . $path . '&limit=1')->assertOk()->assertJsonCount(1, 'data');
            $small = $queries;
            $queries = 0;
            $this->getJson('/api/v1' . $path . '&limit=12')->assertOk()->assertJsonCount(12, 'data');
            $this->assertTrue($small > 0 && $small <= 10);
            $this->assertSame($small, $queries);
        }
    }

    public function testDiscoverDocumentationExportsQueryParametersAndBothResourceTypes(): void
    {
        $html = $this->get('/')->assertOk()->content();
        preg_match('/<script id="reference-data" type="application\/json">(.*?)<\/script>/s', $html, $matches);
        $reference = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        $entries = $reference['groups']['Discover'];

        $this->assertCount(2, $entries);
        $this->assertSame('/api/v1/discover', $entries[0]['uri']);
        $this->assertSame('/api/v1/discover/people', $entries[1]['uri']);
        $this->assertSame(['q', 'page', 'limit', 'sort'], array_column($entries[0]['parameters'], 'name'));
        $this->assertSame(['q', 'page', 'limit'], array_column($entries[1]['parameters'], 'name'));

        foreach ($entries as $entry) {
            $this->assertFalse($entry['auth']);
            $this->assertSame('GET', $entry['method']);
            $this->assertSame(null, $entry['contentType']);
            $this->assertSame(120, $entry['rate']);
            $this->assertSame(18, $entry['response']['meta']['per_page']);
            $this->assertTrue(isset($entry['errors']['422']));

            foreach ($entry['parameters'] as $parameter) {
                $this->assertSame('query', $parameter['in']);
            }
        }

        $this->assertTrue(isset($entries[0]['response']['data'][0]['video_url']));
        $this->assertTrue(isset($entries[1]['response']['data'][0]['username']));
        $this->assertFalse(isset($entries[1]['response']['data'][0]['email']));
    }
}
