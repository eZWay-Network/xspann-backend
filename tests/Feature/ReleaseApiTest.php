<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideo;
use App\Models\{Audio, ArtistPlaylist, User, Video};
use App\Services\{MediaProcessor, Releases, StorageService};
use Tests\Support\{ArtistFixtures, BillingFixtures};
use Tests\TestCase;

class ReleaseApiTest extends TestCase
{
    use ArtistFixtures, BillingFixtures;

    private function draft(User $owner, array $attributes = []): Video
    {
        storage('local')->put("videos/$owner->id/draft.mp4", '0123456789');
        storage('local')->put("thumbnails/$owner->id/draft.jpg", 'thumbnail');

        return $this->makeVideo($owner, [
            'storage_path' => "local:videos/$owner->id/draft.mp4", 'thumbnail_url' => "local:thumbnails/$owner->id/draft.jpg",
            'status' => 'draft', 'processing_state' => 'ready', 'publication_state' => 'draft', 'publication_intent' => 'draft',
            ...$attributes,
        ]);
    }

    public function testDraftCreationEncodingAndOriginalSoundStayPrivateUntilExplicitPublication(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser('other');
        $artist = $this->makeArtist($owner);
        storage('public')->put('videos/1/public.mp4', 'already public');
        storage('local')->put('videos/1/source.mp4', 'private source');
        \App\Services\PendingUploads::track($owner->id, 'videos/1/source.mp4', 'local', true);
        $this->asUser($owner)->postJson('/api/v1/videos', [...$this->videoRights(), 'storage_path' => 'public:videos/1/public.mp4', 'publication_intent' => 'draft'])->assertStatus(422);
        $id = $this->postJson('/api/v1/videos', [...$this->videoRights(), 'storage_path' => 'local:videos/1/source.mp4', 'publication_intent' => 'draft'])
            ->assertStatus(201)->assertJsonPath('data.processing_state', 'processing')->assertJsonPath('data.publication_state', 'draft')->json('data.id');
        $this->app->instance(MediaProcessor::class, new class extends MediaProcessor {
            public function video(Video $video): array
            {
                foreach (['videos/1/render.mp4', 'thumbnails/1/render.jpg', 'sounds/1/original.m4a'] as $path) {
                    storage('local')->put($path, 'private rendered bytes');
                }
                return ['storage_path' => 'local:videos/1/render.mp4', 'thumbnail_url' => 'local:thumbnails/1/render.jpg', 'audio_path' => 'local:sounds/1/original.m4a', 'duration' => 12];
            }
        });
        (new ProcessVideo($id))->handle();
        (new ProcessVideo($id))->handle();
        $video = Video::findOrFail($id);
        $this->assertSame('ready', $video->processing_state);
        $this->assertSame('draft', $video->status);
        $this->assertSame(2, $video->version);
        $this->assertDatabaseCount('audios', 1);
        $this->assertFalse(storage('local')->exists('videos/1/source.mp4'));
        $this->getJson('/api/v1/audios')->assertOk()->assertJsonCount(0, 'data');
        $this->get('/api/v1/me/videos/' . $id . '/preview/original_audio')->assertOk()->assertSee('private rendered bytes');
        $playlist = ArtistPlaylist::create(['artist_id' => $artist->id, 'title' => 'Future release']);
        query('artist_playlist_items')->insert(['playlist_id' => $playlist->id, 'video_id' => $id, 'position' => 0]);
        $this->asUser($other)->getJson('/api/v1/videos/' . $id)->assertStatus(404);
        foreach (['video', 'thumbnail', 'original_audio', '..', 'storage_path'] as $asset) {
            $this->get('/api/v1/me/videos/' . $id . '/preview/' . $asset)->assertStatus(404);
        }
        $this->getJson('/api/v1/artists/' . $artist->id . '/videos')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/artists/' . $artist->id . '/playlists/' . $playlist->id)->assertOk()->assertJsonCount(0, 'data.videos');
        $url = StorageService::publicUrl($video->storage_path);
        $mediaUrl = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);
        $this->get($mediaUrl)->assertStatus(404);
        $this->asUser($owner)->postJson('/api/v1/me/artist/releases/' . $id . '/publish', ['version' => 2])->assertOk()->assertJsonPath('data.publication_state', 'published');
        $this->flushHeaders()->get($mediaUrl)->assertOk()->assertSee('private rendered bytes');
        $this->getJson('/api/v1/audios')->assertOk()->assertJsonCount(1, 'data');
    }

    public function testOwnerPreviewSupportsRangesAndRechecksPrivacyAndModeration(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $video = $this->draft($owner);
        $path = "/api/v1/me/videos/$video->id/preview/video";
        $this->get($path)->assertStatus(401);
        $this->asUser($owner)->get($path, ['Range' => 'bytes=2-5'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 2-5/10')->assertSee('2345');
        $this->get($path, ['Range' => 'bytes=-3'])->assertStatus(206)->assertSee('789');
        $this->get($path, ['Range' => 'bytes=8-'])->assertStatus(206)->assertSee('89');
        foreach (['bytes=10-', 'bytes=5-2', 'bytes=0-1,4-5', 'bytes=-0', 'bytes=-'] as $range) {
            $this->get($path, ['Range' => $range])->assertStatus(416);
        }
        $video->update(['moderation_status' => 'restricted']);
        $this->get($path)->assertStatus(404);
        $this->getJson("/api/v1/me/artist/releases/$video->id")->assertOk()->assertJsonPath('data.preview', null);
        $video->update(['moderation_status' => 'approved', 'status' => 'deleted']);
        $this->get($path)->assertStatus(404);
    }

    public function testSchedulingUsesBillingAndDowngradePausesWithoutEarlyPublication(): void
    {
        $this->billingSetup();
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $video = $this->draft($owner);
        $this->asUser($owner);
        $path = "/api/v1/me/artist/releases/$video->id";
        $input = ['version' => 1, 'publish_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600), 'timezone' => 'UTC'];
        $this->postJson($path . '/schedule', $input)->assertStatus(403);
        $attempt = $this->checkout();
        $invoice = $this->stripe->complete($attempt->session_id);
        $this->event('invoice.paid', $invoice);
        $this->postJson($path . '/schedule', $input)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'scheduled');
        app(Releases::class)->runDue();
        $this->assertSame('scheduled', $video->refresh()->status);
        query('billing_paid_periods')->where('id', '>', 0)->update(['ends_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        app(Releases::class)->runDue();
        app(Releases::class)->runDue();
        $this->getJson($path)->assertOk()->assertJsonPath('data.publication_state', 'draft')->assertJsonPath('data.error_code', 'scheduling_access_ended');
        $this->assertDatabaseCount('notifications', 1);
        $this->postJson($path . '/cancel-schedule', ['version' => 3])->assertOk()->assertJsonPath('data.publish_at', null);
        $this->postJson($path . '/publish', ['version' => 4])->assertOk()->assertJsonPath('data.status', 'published');
    }

    public function testDueWorkerCatchesUpOnceAndRespectsVersionAndCancellation(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $video = $this->draft($owner);
        $this->artistPlan($owner, 'artist_pro');
        $this->asUser($owner);
        $path = "/api/v1/me/artist/releases/$video->id";
        $input = ['version' => 1, 'publish_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600), 'timezone' => 'UTC'];
        $this->postJson($path . '/schedule', $input)->assertOk();
        $this->postJson($path . '/schedule', $input)->assertStatus(409);
        $this->patchJson($path, ['version' => 2, 'caption' => 'Cannot edit a scheduled release'])->assertStatus(409);
        $this->postJson($path . '/cancel-schedule', ['version' => 2])->assertOk();
        app(Releases::class)->runDue();
        $this->assertSame('draft', $video->refresh()->status);
        $this->patchJson($path, ['version' => 3, 'caption' => 'New caption', 'reuse_content' => false])->assertOk()->assertJsonPath('data.version', 4);
        $this->postJson($path . '/schedule', [...$input, 'version' => 4])->assertOk();
        $video->refresh()->update(['publish_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        app(Releases::class)->runDue();
        $this->assertSame('published', $video->refresh()->status);
        $published = $video->published_at->toDateTimeString();
        app(Releases::class)->runDue();
        $this->assertSame(6, $video->refresh()->version);
        $this->assertSame($published, $video->published_at->toDateTimeString());
        $this->postJson($path . '/schedule', [...$input, 'version' => 6])->assertStatus(409);
    }

    public function testInvalidTimezoneOffsetsPastTimesAndSourceRestrictionsCannotPublish(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $video = $this->draft($owner);
        $this->artistPlan($owner, 'artist_pro');
        $this->asUser($owner);
        $path = "/api/v1/me/artist/releases/$video->id";
        foreach ([
            ['2099-02-30T12:00:00Z', 'UTC'], ['2099-01-01T12:00:00', 'UTC'],
            ['2099-01-01T12:00:00Z', 'Asia/Dhaka'], ['2099-01-01T12:00:00+06:00', 'not/a-zone'],
            ['2000-01-01T12:00:00Z', 'UTC'], ['2099-03-08T02:30:00-05:00', 'America/New_York'],
        ] as [$at, $timezone]) {
            $this->postJson($path . '/schedule', ['version' => 1, 'publish_at' => $at, 'timezone' => $timezone])->assertStatus(422);
        }
        $this->postJson($path . '/schedule', ['version' => 1, 'publish_at' => '2099-01-01T12:00:00+06:00', 'timezone' => 'Asia/Dhaka'])->assertOk()->assertJsonPath('data.status', 'scheduled');
        $video->refresh()->update(['moderation_status' => 'restricted']);
        app(Releases::class)->runDue();
        $this->assertSame('draft', $video->refresh()->status);
        $this->assertSame('release_ineligible', $video->release_error);
        $this->postJson($path . '/publish', ['version' => 3])->assertStatus(409);
        $this->asUser($this->makeUser('other'))->postJson($path . '/cancel-schedule', ['version' => 3])->assertStatus(404);
    }
    public function testPrivateUploadsCannotBeReusedOrConvertedThroughLegacyImmediateActions(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $source = "videos/$owner->id/fresh.mp4";
        storage('local')->put($source, 'source');
        $input = [...$this->videoRights(), 'storage_path' => 'local:' . $source, 'publication_intent' => 'draft'];
        $this->postJson('/api/v1/videos', $input)->assertStatus(422);
        \App\Services\PendingUploads::track($owner->id, $source, 'local', true);
        $this->postJson('/api/v1/videos', [...$input, 'publication_intent' => 'immediate'])->assertStatus(422);
        $this->postJson('/api/v1/videos', $input)->assertStatus(201);
        $this->postJson('/api/v1/videos', $input)->assertStatus(422);
        query('pending_uploads')->delete();
        $this->postJson('/api/v1/videos', [...$input, 'publication_intent' => 'immediate'])->assertStatus(422);
        $immediate = $this->makeVideo($owner, ['status' => 'processing']);
        $this->postJson("/api/v1/me/artist/releases/$immediate->id/cancel-schedule", ['version' => 1])->assertStatus(409);
        $this->assertSame('immediate', $immediate->refresh()->publication_intent);
    }

    public function testMissingMediaPausesOneReleaseWhileOtherDueReleasesPublish(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->artistPlan($owner, 'artist_pro');
        $attributes = ['publication_state' => 'scheduled', 'status' => 'scheduled', 'publish_at' => gmdate('Y-m-d H:i:s', time() - 60)];
        $missing = $this->draft($owner, [...$attributes, 'storage_path' => "local:videos/$owner->id/missing.mp4"]);
        $ready = $this->draft($owner, $attributes);
        $processing = $this->draft($owner, [...$attributes, 'processing_state' => 'processing', 'status' => 'processing']);
        app(Releases::class)->runDue();
        $this->assertSame('media_unavailable', $missing->refresh()->release_error);
        $this->assertSame('draft', $missing->publication_state);
        $this->assertSame('published', $ready->refresh()->publication_state);
        $this->assertSame('media_not_ready', $processing->refresh()->release_error);
        app(Releases::class)->runDue();
        $this->assertDatabaseCount('notifications', 2);
        $this->asUser($owner)->postJson("/api/v1/me/artist/releases/$missing->id/publish", ['version' => 2])->assertStatus(422);
    }

    public function testS3PreviewsAreOwnerScopedShortLivedAndRejectPublicAcls(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->useS3(['temporary_urls' => true]);
        $video = $this->makeVideo($owner, [
            'storage_path' => "s3:videos/$owner->id/private.mp4", 'status' => 'draft',
            'publication_intent' => 'draft', 'processing_state' => 'ready', 'publication_state' => 'draft',
        ]);
        $path = "/api/v1/me/videos/$video->id/preview/video";
        $location = $this->asUser($owner)->get($path)->assertStatus(302)->assertHeader('Cache-Control', 'private, no-store')->response->getHeaders()['Location'];
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('60', $query['X-Amz-Expires']);
        $this->assertTrue(isset($query['X-Amz-Signature']));
        $this->asUser($this->makeUser('foreign'))->get($path)->assertStatus(404);
        $this->app->mergeConfig(['storage' => ['disks' => ['s3' => ['acl' => 'public-read']]]]);
        $this->asUser($owner)->get($path)->assertStatus(503);
    }

    public function testDstOverlapIsUnambiguousAndMalformedRouteIdsDoNotReachControllers(): void
    {
        $this->assertSame('2099-11-01 05:30:00', Releases::at('2099-11-01T01:30:00-04:00', 'America/New_York'));
        $this->assertSame('2099-11-01 06:30:00', Releases::at('2099-11-01T01:30:00-05:00', 'America/New_York'));
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner)->getJson('/api/v1/me/artist/releases/not-a-number')->assertStatus(404);
        $this->get('/api/v1/me/videos/not-a-number/preview/video')->assertStatus(404);
        $this->getJson('/api/v1/me/artist/releases/999999999999999999999999999')->assertStatus(404);
    }

    public function testOwnerEncodingRetryIsIdempotentAndRecoversAfterQueueFailure(): void
    {
        $owner = $this->makeUser();
        $video = $this->draft($owner, ['status' => 'failed', 'processing_state' => 'failed']);
        $path = "/api/v1/me/videos/$video->id/retry";
        $this->asUser($owner);
        \Spark\Facades\Schema::rename('jobs', 'jobs_unavailable');
        try {
            try {
                $this->postJson($path);
                $this->fail('Queue outage was silently accepted.');
            } catch (\Throwable $exception) {
                $this->assertSame('failed', $video->refresh()->processing_state);
                $this->assertSame('encoding_dispatch_failed', $video->release_error);
            }
        } finally {
            \Spark\Facades\Schema::rename('jobs_unavailable', 'jobs');
        }
        $this->postJson($path)->assertOk()->assertJsonPath('data.processing_state', 'processing');
        $this->postJson($path)->assertOk();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('draft', $video->refresh()->publication_intent);
        $this->asUser($this->makeUser('foreign'))->postJson($path)->assertStatus(404);
    }

    public function testScheduleStorageAndDueChecksStayUtcOnANonUtcServer(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->artistPlan($owner, 'artist_pro');
        $video = $this->draft($owner);
        $this->asUser($owner);
        $future = time() + 3600;
        $timezone = date_default_timezone_get();
        try {
            date_default_timezone_set('Asia/Dhaka');
            $this->postJson("/api/v1/me/artist/releases/$video->id/schedule", [
                'version' => 1, 'publish_at' => gmdate('Y-m-d\TH:i:s\Z', $future), 'timezone' => 'UTC',
            ])->assertOk()->assertJsonPath('data.publish_at', gmdate('Y-m-d\TH:i:s', $future) . '.000000Z');
            $this->assertSame($future, $video->refresh()->publish_at->getTimestamp());
            app(Releases::class)->runDue();
            $this->assertSame('scheduled', $video->refresh()->status);
            $video->update(['publish_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
            app(Releases::class)->runDue();
            $this->assertSame('published', $video->refresh()->status);
            $this->assertTrue(abs($video->published_at->getTimestamp() - time()) <= 2);
        } finally {
            date_default_timezone_set($timezone);
        }
    }

    public function testChangedRightsDeclarationPausesAScheduleUntilOwnerAcceptsCurrentTerms(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->artistPlan($owner, 'artist_pro');
        $video = $this->draft($owner, ['publication_state' => 'scheduled', 'status' => 'scheduled', 'publish_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $this->app->mergeConfig(['policies' => ['rights_version' => 'new-rights-declaration']]);
        app(Releases::class)->runDue();
        $this->assertSame('release_ineligible', $video->refresh()->release_error);
        $path = "/api/v1/me/artist/releases/$video->id";
        $this->asUser($owner)->postJson($path . '/publish', ['version' => 2])->assertStatus(409);
        $this->patchJson($path, ['version' => 2, 'rights_confirmed' => true, 'rights_version' => 'new-rights-declaration'])->assertOk();
        $this->postJson($path . '/publish', ['version' => 3])->assertOk()->assertJsonPath('data.publication_state', 'published');
    }

}
