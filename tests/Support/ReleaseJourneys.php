<?php

namespace Tests\Support;

use App\Jobs\{DeliverAnnouncements, ProcessPrivacyRequest, PromotionMaintenance, PublishReleases};
use App\Models\{User, Video};
use App\Services\{AccountSecurity, Releases, Totp};
use App\Services\Billing\{Billing, PaymentProvider, Webhooks};
use Spark\Console\Process;
use Spark\Queue\Queue;
use Spark\Testing\TestResponse;

/** The same wire-level release journey runs on SQLite and opt-in disposable MySQL. */
trait ReleaseJourneys
{
    use PromotionFixtures;

    private ?HttpServer $journeyServer = null;
    private string $journeyConfiguration;
    private string $stripeState;

    public function testFarFutureReleaseScheduleRoundTripsOnTheSelectedDatabase(): void
    {
        $this->billingSetup();
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $attempt = $this->checkout();
        $this->event('invoice.paid', $this->stripe->complete($attempt->session_id));
        $path = "videos/$owner->id/future.mp4";
        storage('local')->put($path, 'private future release');
        $video = $this->makeVideo($owner, [
            'storage_path' => 'local:' . $path, 'thumbnail_url' => null,
            'status' => 'draft', 'processing_state' => 'ready', 'publication_state' => 'draft', 'publication_intent' => 'draft',
        ]);
        $this->postJson('/api/v1/me/artist/releases/' . $video->id . '/schedule', [
            'version' => 1, 'publish_at' => '2099-01-01T12:00:00+06:00', 'timezone' => 'Asia/Dhaka',
        ])->assertOk()->assertJsonPath('data.publish_at', '2099-01-01T06:00:00.000000Z');
        $this->assertSame('2099-01-01 06:00:00', query('videos')->where('id', $video->id)->value('publish_at'));
        app(Releases::class)->runDue();
        $this->assertSame('scheduled', $video->refresh()->publication_state);
        $this->flushHeaders()->getJson('/api/v1/videos/' . $video->id)->assertStatus(404);
    }

    private function startJourney(): void
    {
        $this->billingSetup();
        $this->promotionSetup();
        $binaries = [];
        foreach (['ffmpeg', 'ffprobe'] as $binary) {
            foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
                if (is_executable("$directory/$binary")) {
                    $binaries[$binary] = "$directory/$binary";
                    break;
                }
            }
        }
        if (count($binaries) !== 2) {
            $this->markTestSkipped('Install FFmpeg and FFprobe for the release HTTP journey.');
        }
        $this->app->mergeConfig([
            'app' => $binaries,
            'cache' => ['default' => 'database'],
            'relay' => ['apps' => [['key' => 'release-key', 'secret' => 'release-secret']]],
        ]);
        $this->stripeState = $this->storagePath . '/stripe-state.json';
        $this->saveStripeState();
        $config = [];
        foreach (['app', 'auth', 'database', 'session', 'cache', 'queue', 'storage', 'cors', 'policies', 'artists', 'billing', 'promotions', 'communications', 'relay', 'staff', 'staff-navigation', 'inertia'] as $key) {
            $config[$key] = config($key);
        }
        $config['testing'] = ['fake_stripe' => true, 'stripe_state' => $this->stripeState];
        $this->journeyConfiguration = $this->storagePath . '/journey-config.json';
        file_put_contents($this->journeyConfiguration, json_encode($config, JSON_THROW_ON_ERROR));
        chmod($this->journeyConfiguration, 0600);
        $this->restartJourney();
    }

    private function restartJourney(): void
    {
        $this->journeyServer?->stop();
        $this->journeyServer = new HttpServer(dirname(__DIR__) . '/Fixtures/http-router.php', $this->storagePath, [
            'XSPANN_HTTP_TEST_CONFIG' => $this->journeyConfiguration,
        ]);
    }

    private function wire(string $method, string $path, ?string $token = null, ?array $body = null, array $headers = []): TestResponse
    {
        // JWT audience remains the configured virtual origin across worker restarts.
        $origin = parse_url(config('app.url'));
        $headers += ['Host' => $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : ''), 'Accept' => 'application/json'];
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        return $this->journeyServer->request($method, '/api/v1' . $path, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR), $headers);
    }

    private string $staffWireCsrf = '';
    private string $staffWireVersion = '';

    private function staffWire(string $method, string $path, ?array $body = null, bool $evidence = false): TestResponse
    {
        $origin = parse_url(config('app.url'));
        $headers = ['Host' => $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : ''),
            'Accept' => $evidence ? 'application/json' : 'text/html'];
        if (!$evidence) {
            $headers += ['X-Inertia' => 'true', 'X-Inertia-Version' => $this->staffWireVersion];
        }
        if ($body !== null) {
            $headers += ['Content-Type' => 'application/json', 'X-CSRF-TOKEN' => $this->staffWireCsrf];
        }
        $response = $this->journeyServer->request($method, $path, $body === null ? null : json_encode($body), $headers, true);
        if ($response->response->getStatusCode() === 200 && !$evidence) {
            $this->staffWireCsrf = $response->json('props.csrf_token');
            $this->staffWireVersion = $response->json('version');
        }

        return $response;
    }

    private function saveStripeState(): void
    {
        file_put_contents($this->stripeState, json_encode(['objects' => $this->stripe->objects, 'keys' => $this->stripe->keys], JSON_THROW_ON_ERROR));
        chmod($this->stripeState, 0600);
    }

    private function loadStripeState(): void
    {
        $state = json_decode(file_get_contents($this->stripeState), true, flags: JSON_THROW_ON_ERROR);
        $this->stripe->objects = $state['objects'];
        $this->stripe->keys = $state['keys'];
        $this->app->instance(PaymentProvider::class, $this->stripe);
    }

    private function login(User $user): string
    {
        return $this->wire('POST', '/auth/login', body: ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()->json('data.token');
    }

    public function testIndependentHttpWorkersCannotSpendTheSamePromotionCredit(): void
    {
        $this->startJourney();
        $second = null;
        $multi = curl_multi_init();
        $handles = [];
        $locked = false;
        $pdo = db()->getPdo();
        try {
            [$owner] = $this->paidArtist();
            $token = $this->login($owner);
            $second = new HttpServer(dirname(__DIR__) . '/Fixtures/http-router.php', $this->storagePath, ['XSPANN_HTTP_TEST_CONFIG' => $this->journeyConfiguration]);
            foreach ([$this->journeyServer, $second] as $index => $server) {
                $boost = \App\Services\Promotions\Boosts::create($owner, $this->boostInput($this->makeVideo($owner)->id));
                $handle = curl_init($server->url . "/api/v1/me/artist/boosts/$boost->id/submit");
                curl_setopt_array($handle, [
                    CURLOPT_CUSTOMREQUEST => 'POST', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => ['Host: localhost:8080', 'Accept: application/json', 'Content-Type: application/json',
                        'Authorization: Bearer ' . $token, 'Idempotency-Key: release-contention-' . $index],
                    CURLOPT_POSTFIELDS => '{"version":1}',
                ]);
                $handles[] = $handle;
                curl_multi_add_handle($multi, $handle);
            }
            if (db()->getDriver() === 'sqlite') {
                $pdo->exec('BEGIN IMMEDIATE');
            } else {
                $pdo->beginTransaction();
                $pdo->exec('UPDATE promotion_locks SET revision = revision + 1 WHERE id = 1');
            }
            $locked = true;
            $deadline = microtime(true) + 0.2;
            do {
                curl_multi_exec($multi, $running);
                curl_multi_select($multi, 0.02);
            } while (microtime(true) < $deadline);
            $pdo->exec('COMMIT');
            $locked = false;
            do {
                curl_multi_exec($multi, $running);
                if ($running) {
                    curl_multi_select($multi, 0.1);
                }
            } while ($running);
            $statuses = [];
            foreach ($handles as $handle) {
                $this->assertSame('', curl_error($handle));
                $statuses[] = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            }
            sort($statuses);
            $this->assertSame([202, 409], $statuses);
            $this->assertSame(0, \App\Services\Promotions\Credits::balance($owner->id)['available_cents']);
            $this->assertSame(500, \App\Services\Promotions\Credits::balance($owner->id)['reserved_cents']);
            $this->assertDatabaseCount('promotion_slots', 1);
            $this->assertDatabaseCount('promotion_reservations', 1);
        } finally {
            if ($locked) {
                $pdo->exec('ROLLBACK');
            }
            foreach ($handles as $handle) {
                curl_multi_remove_handle($multi, $handle);
            }
            curl_multi_close($multi);
            $second?->stop();
            $this->journeyServer?->stop();
        }
    }

    public function testRightsRestrictionStopsReleasePlaylistAnnouncementsAndPaidPlacementTogether(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $this->promotionSetup();
        $fan = $this->subscriber($artist->id);
        $staff = $this->promotionStaff();
        $boost = $this->activateBoost($owner, $staff);
        $video = Video::find($boost->video_id);
        $audio = \App\Models\Audio::create([
            'user_id' => $owner->id, 'title' => 'Rights review sound', 'storage_path' => "sounds/$owner->id/review.m4a",
            'status' => 'ready', 'reuse_allowed' => true,
        ]);
        $video->update(['audio_id' => $audio->id]);
        $draft = $this->makeVideo($owner, [
            'audio_id' => $audio->id, 'storage_path' => "local:videos/$owner->id/upcoming.mp4", 'status' => 'scheduled',
            'processing_state' => 'ready', 'publication_state' => 'scheduled', 'publication_intent' => 'draft',
            'publish_at' => Billing::timestamp(time() + 3600),
        ]);
        storage('local')->put("videos/$owner->id/upcoming.mp4", 'private media');
        $this->asUser($owner);
        $playlist = $this->postJson('/api/v1/me/artist/playlists', ['title' => 'Rights review'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/me/artist/playlists/$playlist/items", ['version' => 1, 'video_ids' => [$video->id]])->assertOk();
        $announcement = $this->postJson('/api/v1/me/artist/announcements', ['title' => 'Release update', 'body' => 'New original release', 'video_id' => $video->id])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/me/artist/announcements/$announcement/send", ['version' => 1], ['Idempotency-Key' => 'rights-integration-send'])->assertAccepted();
        $report = $this->asUser($fan)->postJson('/api/v1/reports', ['audio_id' => $audio->id, 'reason' => 'Review conflicting rights'])->assertCreated()->json('data.id');
        \App\Services\Moderation::decide($report, $staff, ['action' => 'restrict', 'reason' => 'Rights are under review.', 'version' => 1]);
        (new PublishReleases())->handle();
        (new DeliverAnnouncements())->handle();
        (new PromotionMaintenance())->handle();
        $this->getJson('/api/v1/videos/' . $video->id)->assertNotFound();
        $this->getJson("/api/v1/artists/$artist->id/playlists/$playlist")->assertJsonCount(0, 'data.videos');
        $this->getJson('/api/v1/promotions/placements?surface=web_promoted_videos')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/me/announcements/$announcement")->assertNotFound();
        $this->assertDatabaseHas('announcement_deliveries', ['announcement_id' => $announcement, 'status' => 'suppressed']);
        $this->assertSame('draft', $draft->refresh()->publication_state);
        $this->assertSame('interrupted', \App\Services\Promotions\Boosts::find($boost->id)->status);
        $this->assertSame(500, \App\Services\Promotions\Credits::balance($owner->id)['available_cents']);
        $this->asUser($owner)->get("/api/v1/me/videos/$draft->id/preview/video")->assertNotFound();
    }

    public function testFanArtistAndOperatorHttpJourneySurvivesRestartDowngradeAndDeletion(): void
    {
        $owner = $this->makeUser('journeyartist');
        $fan = $this->makeUser('journeyfan');
        $outside = $this->makeUser('journeyoutside');
        $staff = $this->makeUser('journeystaff');
        $secret = Totp::secret();
        AccountSecurity::enrollStaff($staff, 'admin', $secret, Totp::code($secret, intdiv(time(), 30) - 1));
        $this->startJourney();
        try {
            $artistToken = $this->login($owner);
            $fanToken = $this->login($fan);
            $outsideToken = $this->login($outside);
            $staffToken = $this->login($staff);
            $this->wire('GET', '/policies')->assertOk();
            $this->wire('GET', '/plans')->assertOk()->assertJsonPath('data.live_sales_enabled', false);
            $artist = $this->wire('PUT', '/me/artist', $artistToken, ['stage_name' => 'Journey Artist', 'slug' => 'journey-artist'])
                ->assertCreated()->json('data.id');
            $this->wire('GET', '/me/entitlements', $artistToken)->assertJsonPath('data.capabilities.plan', 'free');
            $this->wire('POST', "/artists/$artist/follow", $fanToken)->assertOk();
            $this->wire('POST', "/users/$fan->id/follow", $artistToken)->assertCreated();
            $this->wire('GET', "/artists/$artist/update-subscription", $fanToken)->assertJsonPath('data.subscribed', false);
            $conversation = $this->wire('POST', '/conversations', $fanToken, ['recipient_user_id' => $owner->id])->assertOk()->json('data.id');
            $messageInput = $this->messageInput('A private fan message');
            $message = $this->wire('POST', "/conversations/$conversation/messages", $fanToken, $messageInput)->assertCreated()->json('data.id');
            $this->wire('POST', "/conversations/$conversation/messages", $fanToken, $messageInput)->assertOk()->assertJsonPath('data.id', $message);
            $this->wire('GET', "/conversations/$conversation/messages", $outsideToken)->assertNotFound();
            $this->wire('PUT', "/artists/$artist/update-subscription", $fanToken, ['subscribed' => true, 'consent_version' => config('communications.consent_version')])->assertOk();

            $checkoutInput = ['plan_code' => 'artist_pro', 'offer_version' => config('billing.offer_version')];
            $checkout = $this->wire('POST', '/billing/checkout', $artistToken, $checkoutInput, ['Idempotency-Key' => 'journey-checkout-key'])->assertOk()->json('data');
            $this->wire('POST', '/billing/checkout', $artistToken, $checkoutInput, ['Idempotency-Key' => 'journey-checkout-key'])->assertOk()->assertJsonPath('data.reference', $checkout['reference']);
            $this->wire('GET', '/billing/checkouts/' . $checkout['reference'], $outsideToken)->assertNotFound();
            $this->loadStripeState();
            $attempt = query('billing_checkouts')->first();
            $invoice = $this->stripe->complete($attempt->session_id);
            $this->saveStripeState();
            $event = json_encode(['id' => 'evt_release_journey', 'type' => 'invoice.paid', 'created' => time(), 'livemode' => false,
                'api_version' => '2024-06-20', 'data' => ['object' => ['id' => $invoice]]], JSON_THROW_ON_ERROR);
            $timestamp = time();
            $signature = 't=' . $timestamp . ',v1=' . hash_hmac('sha256', "$timestamp.$event", 'whsec_test');
            for ($i = 0; $i < 2; $i++) {
                $this->journeyServer->request('POST', '/api/v1/webhooks/stripe', $event, ['Content-Type' => 'application/json', 'Stripe-Signature' => $signature])->assertOk();
            }
            $this->assertDatabaseCount('billing_webhooks', 1);
            app(Queue::class)->work(once: true, timeout: 15, sleep: 0, queue: 'billing');
            $this->wire('GET', '/me/entitlements', $artistToken)->assertOk()->assertJsonPath('data.capabilities.plan', 'artist_pro');
            $this->wire('GET', '/me/artist/boost-balance', $artistToken)->assertJsonPath('data.available_cents', 500);

            $file = $this->storagePath . '/release.mp4';
            Process::run([config('app.ffmpeg'), '-nostdin', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=32x32:d=0.5', '-c:v', 'mpeg4', $file], timeout: 15)->throw();
            $upload = $this->journeyServer->request('POST', '/api/v1/uploads/videos/local', [
                'file' => new \CURLFile($file, 'video/mp4', 'release.mp4'), 'publication_intent' => 'draft',
            ], ['Host' => 'localhost:8080', 'Accept' => 'application/json', 'Authorization' => 'Bearer ' . $artistToken])->assertCreated()->json('data');
            $video = $this->wire('POST', '/videos', $artistToken, [
                ...$this->videoRights(), 'storage_path' => $upload['storage_path'], 'publication_intent' => 'draft', 'visibility' => 'public',
            ])->assertCreated()->json('data.id');
            app(Queue::class)->work(once: true, timeout: 30, sleep: 0, queue: 'default');
            $release = $this->wire('GET', "/me/artist/releases/$video", $artistToken)->assertOk()->assertJsonPath('data.processing_state', 'ready')->json('data');
            $this->wire('GET', "/me/videos/$video/preview/video", $artistToken, headers: ['Range' => 'bytes=0-15'])->assertStatus(206);
            $this->wire('GET', "/me/videos/$video/preview/video", $fanToken)->assertNotFound();
            $this->wire('GET', "/videos/$video", $fanToken)->assertNotFound();
            $scheduled = $this->wire('POST', "/me/artist/releases/$video/schedule", $artistToken, [
                'version' => $release['version'], 'publish_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600), 'timezone' => 'UTC',
            ])->assertOk()->json('data');
            $this->wire('POST', "/me/artist/releases/$video/publish", $artistToken, ['version' => $release['version']])->assertStatus(409);
            // Simulate an overdue release after downtime without changing the system clock.
            query('videos')->where('id', $video)->update(['publish_at' => Billing::timestamp(time() - 60)]);
            $this->restartJourney();
            (new PublishReleases())->handle();
            (new PublishReleases())->handle();
            $this->wire('GET', "/videos/$video", $fanToken)->assertOk()->assertJsonPath('data.publication_state', 'published');
            $this->assertSame($scheduled['version'] + 1, Video::find($video)->version);
            $playlist = $this->wire('POST', '/me/artist/playlists', $artistToken, ['title' => 'Release journey'])->assertCreated()->json('data.id');
            $this->wire('PUT', "/me/artist/playlists/$playlist/items", $artistToken, ['version' => 1, 'video_ids' => [$video]])->assertOk();

            $announcement = $this->wire('POST', '/me/artist/announcements', $artistToken, ['title' => 'New release', 'body' => 'My original release is available', 'video_id' => $video])->assertCreated()->json('data.id');
            $this->wire('POST', "/me/artist/announcements/$announcement/send", $artistToken, ['version' => 1], ['Idempotency-Key' => 'journey-announcement-key'])->assertAccepted();
            (new DeliverAnnouncements())->handle();
            $this->wire('GET', "/me/announcements/$announcement", $fanToken)->assertOk();
            $this->wire('GET', "/me/announcements/$announcement", $outsideToken)->assertNotFound();
            $this->wire('PATCH', "/me/announcements/$announcement/read", $fanToken, [])->assertOk();

            $boost = $this->wire('POST', '/me/artist/boosts', $artistToken, $this->boostInput($video))->assertCreated()->json('data.id');
            $this->wire('POST', "/me/artist/boosts/$boost/submit", $artistToken, ['version' => 1], ['Idempotency-Key' => 'journey-boost-key'])->assertAccepted();
            $this->wire('GET', '/admin/boosts', $fanToken)->assertNotFound();
            $this->wire('GET', '/admin/boosts', $staffToken)->assertNotFound();
            $this->staffWire('GET', '/login')->assertOk();
            $this->staffWire('POST', '/login', ['email' => $staff->email, 'password' => 'password123'])->assertStatus(303);
            $this->staffWire('GET', '/challenge')->assertOk();
            $this->staffWire('POST', '/challenge', ['mfa_code' => Totp::code($secret, intdiv(time(), 30))])->assertStatus(303);
            $this->staffWire('GET', '/promotions/boosts')->assertOk();
            $this->staffWire('POST', "/promotions/boosts/$boost/decision", ['version' => 2, 'decision' => 'approve', 'reason' => 'Reviewed original creative and rights.'])->assertStatus(303);
            query('promotion_boosts')->where('id', $boost)->update(['starts_at' => Billing::timestamp(time() - 1), 'ends_at' => Billing::timestamp(time() + 86399)]);
            (new PromotionMaintenance())->handle();
            $this->wire('GET', '/promotions/placements?surface=web_promoted_videos', $fanToken)->assertOk()->assertJsonCount(1, 'data');
            $report = $this->wire('POST', '/reports', $fanToken, ['boost_id' => $boost, 'reason' => 'Review sponsored claim.'])->assertCreated()->json('data.id');
            $this->wire('GET', "/admin/reports/$report", $fanToken)->assertNotFound();
            $this->staffWire('GET', "/moderation/reports/$report/evidence", evidence: true)->assertOk()->assertJsonPath('snapshot.video_id', $video);
            $this->staffWire('POST', "/moderation/reports/$report/decision", ['action' => 'dismiss', 'version' => 1, 'reason' => 'Verified the advertising claim.'])->assertStatus(303);
            $this->wire('GET', '/promotions/placements?surface=web_promoted_videos', $fanToken)->assertJsonCount(1, 'data');
            $this->wire('POST', "/users/$owner->id/block", $fanToken)->assertCreated();
            $this->wire('POST', '/reports', $fanToken, ['boost_id' => $boost, 'reason' => 'Hidden sponsored claim.'])->assertNotFound();
            $this->wire('GET', '/promotions/placements?surface=web_promoted_videos', $fanToken)->assertJsonCount(0, 'data');
            $this->wire('GET', "/artists/$artist", $fanToken)->assertNotFound();
            $this->wire('GET', "/me/announcements/$announcement", $fanToken)->assertNotFound();
            $this->wire('POST', "/conversations/$conversation/messages", $fanToken, $this->messageInput())->assertForbidden();
            $this->wire('GET', "/conversations/$conversation/messages?after_id=0", $artistToken)->assertOk()->assertJsonPath('data.0.id', $message);

            $this->wire('POST', '/billing/subscription/cancel', $artistToken, [])->assertOk();
            $this->wire('GET', '/me/entitlements', $artistToken)->assertJsonPath('data.capabilities.plan', 'artist_pro');
            query('billing_paid_periods')->where('id', '>', 0)->update(['ends_at' => Billing::timestamp(time() - 3600)]);
            $this->assertSame(null, Billing::access($owner->id));
            $this->wire('GET', '/me/entitlements', $artistToken)->assertJsonPath('data.capabilities.plan', 'free');
            $this->wire('POST', '/me/artist/announcements', $artistToken, ['title' => 'Expired', 'body' => 'No new paid broadcast'])->assertForbidden();
            $this->wire('GET', "/artists/$artist/playlists/$playlist", $outsideToken)->assertOk()->assertJsonCount(1, 'data.videos');
            $active = $this->wire('GET', "/me/artist/boosts/$boost", $artistToken)->assertOk()->json('data');
            $this->wire('POST', "/me/artist/boosts/$boost/cancel", $artistToken, ['version' => $active['version']])->assertOk();
            $this->wire('GET', '/me/artist/boost-balance', $artistToken)->assertJsonPath('data.available_cents', 500);

            $proof = $this->wire('POST', '/auth/reauthenticate', $artistToken, ['purpose' => 'delete_account', 'password' => 'password123'])->assertOk()->json('data.proof');
            $this->wire('POST', '/me/deletion-requests', $artistToken, ['proof' => $proof])->assertAccepted();
            $this->wire('GET', '/auth/me', $artistToken)->assertUnauthorized();
            $this->loadStripeState();
            $request = query('privacy_requests')->where('kind', 'deletion')->first();
            (new ProcessPrivacyRequest($request->id))->handle();
            $this->assertSame(null, User::find($owner->id));
            $this->assertDatabaseCount('conversations', 0);
            $this->assertSame(0, query('promotion_slots')->whereNull('released_at')->count());
            $this->wire('GET', "/artists/$artist", $outsideToken)->assertNotFound();
        } finally {
            $this->journeyServer?->stop();
        }
    }
}
