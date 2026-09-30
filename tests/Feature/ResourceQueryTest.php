<?php

namespace Tests\Feature;

use App\Http\Resources\VideoResource;
use App\Models\{Comment, Follow, Like, Save, Video};
use Tests\TestCase;

class ResourceQueryTest extends TestCase
{
    public function testListQueryCountsDoNotGrowWithPageSize(): void
    {
        $viewer = $this->makeUser('viewer');
        for ($index = 0; $index < 12; $index++) {
            $author = $this->makeUser('author' . $index);
            $video = $this->makeVideo($author);
            Follow::create(['follower_id' => $viewer->id, 'following_id' => $author->id]);
            Like::create(['user_id' => $viewer->id, 'video_id' => $video->id]);
            Save::create(['user_id' => $viewer->id, 'video_id' => $video->id]);
            Comment::create(['user_id' => $author->id, 'video_id' => 1, 'body' => 'Comment']);
        }
        $this->asUser($viewer);
        $this->getJson('/api/v1/feed?limit=1')->assertOk();
        $this->app->mergeConfig(['app' => ['debug' => true]]);
        $queries = 0;
        event()->addListener('app:db.queryExecuted', function () use (&$queries) {
            $queries++; });

        foreach (['/feed', '/feed/following', '/videos', '/me/liked-videos', '/me/saved-videos', '/users/viewer/following', '/videos/1/comments'] as $path) {
            $queries = 0;
            $this->getJson('/api/v1' . $path . '?limit=1')->assertOk()->assertJsonCount(1, 'data');
            $small = $queries;
            $queries = 0;
            $this->getJson('/api/v1' . $path . '?limit=12')->assertOk()->assertJsonCount(12, 'data');
            $this->assertTrue($small > 0 && $small <= 10);
            $this->assertSame($small, $queries);
        }

        $videos = Video::withApiData($viewer)->get();
        $queries = 0;
        VideoResource::collection($videos)->resolve();
        $this->assertSame(0, $queries);

        $this->flushHeaders();
        $queries = 0;
        $this->getJson('/api/v1/users/suggestions?limit=1')->assertOk();
        $small = $queries;
        $queries = 0;
        $this->getJson('/api/v1/users/suggestions?limit=12')->assertOk()->assertJsonCount(12, 'data');
        $this->assertSame($small, $queries);
    }

    public function testProfilePostsFollowersAndRepliesHaveBoundedQueries(): void
    {
        $owner = $this->makeUser();
        for ($index = 0; $index < 12; $index++) {
            $this->makeVideo($owner);
            $author = $this->makeUser('author' . $index);
            Follow::create(['follower_id' => $author->id, 'following_id' => $owner->id]);
        }
        $parent = Comment::create(['user_id' => $owner->id, 'video_id' => 1, 'body' => 'Parent']);
        for ($index = 0; $index < 12; $index++) {
            Comment::create(['user_id' => $index + 2, 'video_id' => 1, 'parent_id' => $parent->id, 'body' => 'Reply']);
        }
        $this->asUser($owner)->getJson('/api/v1/auth/me')->assertOk();
        $this->app->mergeConfig(['app' => ['debug' => true]]);
        $queries = 0;
        event()->addListener('app:db.queryExecuted', function () use (&$queries) {
            $queries++; });

        foreach (['/me/videos', '/users/alice/videos', '/users/alice/followers', '/comments/' . $parent->id . '/replies'] as $path) {
            $queries = 0;
            $this->getJson('/api/v1' . $path . '?limit=1')->assertOk()->assertJsonCount(1, 'data');
            $small = $queries;
            $queries = 0;
            $this->getJson('/api/v1' . $path . '?limit=12')->assertOk()->assertJsonCount(12, 'data');
            $this->assertTrue($small > 0 && $small <= 10);
            $this->assertSame($small, $queries);
        }
    }
}
