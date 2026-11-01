<?php

namespace Tests\Feature;

use App\Services\Moderation;
use Spark\Facades\DB;
use Tests\Support\StaffBrowser;
use Tests\TestCase;

class StaffCommunityTest extends TestCase
{
    use StaffBrowser;

    public function testMetadataDatesKeepLegacyStorageAndUtcReleaseTimesDistinct(): void
    {
        $this->app->mergeConfig(['app' => ['timezone' => 'Asia/Dhaka']]);
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner);
        DB::table('videos')->where('id', $video->id)->update(['created_at' => '2026-11-01 12:00:00', 'published_at' => '2026-11-01 12:00:00']);
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        $fields = $this->browserPage('/content/videos/' . $video->id)->assertOk()->json('props.fields');
        $values = array_column($fields, 'value', 'label');
        $this->assertSame('2026-11-01T06:00:00Z', $values['Created at']);
        $this->assertSame('2026-11-01T12:00:00Z', $values['Published at']);
    }

    public function testEveryCommunityPageHasExplicitAuthorizedProps(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner);
        $artist = \App\Models\Artist::create(['user_id' => $owner->id, 'stage_name' => 'Artist', 'slug' => 'artist', 'listed' => true, 'data_since' => now()]);
        $audio = \App\Models\Audio::create(['user_id' => $owner->id, 'title' => 'Audio', 'storage_path' => 'sounds/1/test.mp3', 'origin' => 'upload', 'status' => 'ready', 'reuse_allowed' => true]);
        $playlist = \App\Models\ArtistPlaylist::create(['artist_id' => $artist->id, 'title' => 'Playlist', 'visibility' => 'public', 'category' => 'music']);
        $this->browserLogin($this->makeUser('admin'));
        foreach (['/users', '/users/' . $owner->id, '/artists', '/artists/' . $artist->id,
            '/content/videos', '/content/videos/' . $video->id, '/content/audios', '/content/audios/' . $audio->id,
            '/content/playlists', '/content/playlists/' . $playlist->id, '/moderation/reports', '/moderation/appeals', '/moderation/rights',
            '/promotions/boosts', '/promotions/placements', '/promotions/placements/create', '/promotions/surface', '/promotions/credits', '/operations/privacy'] as $path) {
            $response = $this->browserPage($path)->assertOk();
            $this->assertFalse(str_contains($response->content(), $owner->email), $path);
            $this->assertFalse(str_contains($response->content(), 'sounds/1/test.mp3'), $path);
            $this->assertFalse(str_contains($response->content(), 'date_of_birth'), $path);
        }
    }

    public function testStaffCaseRestrictionAndIndependentAppealRestore(): void
    {
        $owner = $this->makeUser('owner');
        $video = $this->makeVideo($owner);
        $initiator = $this->makeUser('initiator');
        $reviewer = $this->makeUser('reviewer');
        $second = $this->makeUser('second');
        $this->browserLogin($initiator, 'moderator');
        $this->browserWrite('/moderation/cases', ['target_type' => 'video', 'target_id' => $video->id, 'reason' => 'Rights require review'])->assertStatus(303);
        $report = DB::table('reports')->first();
        $this->assertSame('staff', $report->source);
        $this->assertSame(null, $report->user_id);
        $this->browserPage('/moderation/reports/' . $report->id)->assertOk()->assertJsonPath('props.actions.decision', null);
        $this->browserWrite('/moderation/reports/' . $report->id . '/decision', ['action' => 'restrict', 'version' => 1, 'reason' => 'Rights concern confirmed'])->assertForbidden();
        $this->browserLogin($reviewer, 'moderator');
        $this->browserWrite('/moderation/reports/' . $report->id . '/assignment', ['version' => 0, 'assignee_id' => $reviewer->id, 'priority' => 'high', 'tag' => 'rights'], 'PATCH')->assertStatus(303);
        $this->assertSame(1, (int) DB::table('reports')->value('version'));
        $this->browserWrite('/moderation/reports/' . $report->id . '/notes', ['body' => 'Internal review note'])->assertStatus(303);
        $this->assertFalse(str_contains(DB::table('report_notes')->value('body'), 'Internal review note'));
        $this->browserPage('/moderation/reports/' . $report->id)->assertOk();
        $this->get('/moderation/reports/' . $report->id . '/evidence', ['Accept' => 'application/json'])->assertOk()->assertJsonPath('snapshot.caption', 'Hello #world');
        $this->browserWrite('/moderation/reports/' . $report->id . '/decision', ['action' => 'restrict', 'version' => 1, 'reason' => 'Rights concern confirmed'])->assertStatus(303);
        $this->assertSame('restricted', $video->refresh()->moderation_status);
        $decision = DB::table('moderation_decisions')->first();
        $appeal = Moderation::appeal($owner, $decision->id, 'Permission is documented.');
        $this->browserWrite('/moderation/appeals/' . $appeal['id'] . '/decision', ['outcome' => 'overturn', 'version' => 1, 'reason' => 'Documentation confirmed'])->assertForbidden();
        $this->browserLogin($second, 'moderator');
        $this->browserPage('/moderation/appeals/' . $appeal['id'])->assertOk();
        $this->browserWrite('/moderation/appeals/' . $appeal['id'] . '/decision', ['outcome' => 'overturn', 'version' => 1, 'reason' => 'Documentation confirmed'])->assertStatus(303);
        $this->assertSame('approved', $video->refresh()->moderation_status);
    }
}
