<?php

namespace Tests\Feature;

use App\Services\{Announcements, Communications};
use App\Services\Billing\Billing;
use Tests\Support\CommunicationFixtures;
use Tests\TestCase;

class AnnouncementApiTest extends TestCase
{
    use CommunicationFixtures;

    public function testConsentIsIndependentVersionedAndPrivate(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $fan = $this->makeUser('fan');
        $this->mutual($owner, $fan);
        $this->asUser($fan)->getJson("/api/v1/artists/$artist->id/update-subscription")->assertOk()->assertJsonPath('data.subscribed', false);
        $this->putJson("/api/v1/artists/$artist->id/update-subscription", ['subscribed' => true])->assertStatus(422);
        $this->putJson("/api/v1/artists/$artist->id/update-subscription", ['subscribed' => true, 'consent_version' => 'old'])->assertStatus(422);
        $this->putJson("/api/v1/artists/$artist->id/update-subscription", ['subscribed' => true, 'consent_version' => config('communications.consent_version')])->assertOk()->assertJsonPath('data.revision', 1);
        $this->putJson("/api/v1/artists/$artist->id/update-subscription", ['subscribed' => true, 'consent_version' => config('communications.consent_version')])->assertOk()->assertJsonPath('data.revision', 1);
        $this->assertDatabaseCount('communication_consent_history', 1);
        $this->asUser($owner)->getJson('/api/v1/me/artist/fans/summary')->assertOk()->assertJsonPath('data.announcement_subscribers_count', 1);
        $this->getJson("/api/v1/artists/$artist->id/update-subscription")->assertOk()->assertJsonPath('data.subscribed', false);
    }

    public function testPaidDraftOwnershipVersionAndContentRules(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $other = $this->makeUser('other');
        $this->makeArtist($other);
        $this->asUser($other)->postJson('/api/v1/me/artist/announcements', ['title' => 'Hello', 'body' => 'World'])->assertForbidden();
        $this->asUser($owner);
        foreach (['https://example.com', 'www.example.com', 'example.com', 'javascript:alert(1)', '[click](//evil)', '<a href="evil">click</a>'] as $body) {
            $this->postJson('/api/v1/me/artist/announcements', ['title' => 'Hello', 'body' => $body])->assertStatus(422);
        }
        $unconfirmed = $this->makeVideo($owner, ['rights_confirmed_at' => null]);
        $this->postJson('/api/v1/me/artist/announcements', ['title' => 'Hello', 'body' => 'World', 'video_id' => $unconfirmed->id])->assertStatus(422);
        $foreign = $this->makeVideo($other);
        $this->postJson('/api/v1/me/artist/announcements', ['title' => 'Hello', 'body' => 'World', 'video_id' => $foreign->id])->assertStatus(422);
        $this->postJson('/api/v1/me/artist/announcements', ['title' => 'Hello', 'body' => 'World', 'user_id' => $other->id])->assertStatus(422);
        $this->postJson('/api/v1/me/artist/announcements', ['title' => 'Hello', 'body' => 'World'])->assertCreated();
        $id = query('announcements')->first()->id;
        $this->patchJson("/api/v1/me/artist/announcements/$id", ['version' => 1, 'body' => 'Updated'])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson("/api/v1/me/artist/announcements/$id", ['version' => 1, 'body' => 'Stale'])->assertStatus(409);
        $this->asUser($other)->getJson("/api/v1/me/artist/announcements/$id")->assertNotFound();
        $this->deleteJson("/api/v1/me/artist/announcements/$id")->assertNotFound();
        $this->assertFalse(str_contains(query('announcements')->first()->body, 'Updated'));
    }

    public function testFrozenAudienceRetryIdempotencyAndReadSynchronization(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $fan = $this->subscriber($artist->id);
        $row = Announcements::create($owner, ['title' => 'Hello', 'body' => 'Latest news']);
        $this->asUser($owner)->postJson("/api/v1/me/artist/announcements/$row->id/send", ['version' => 1])->assertStatus(422);
        $headers = ['Idempotency-Key' => 'send-campaign-one'];
        $this->postJson("/api/v1/me/artist/announcements/$row->id/send", ['version' => 1], $headers)->assertAccepted()->assertJsonPath('data.recipient_count', 1);
        $this->postJson("/api/v1/me/artist/announcements/$row->id/send", ['version' => 1], $headers)->assertAccepted();
        $this->postJson("/api/v1/me/artist/announcements/$row->id/send", ['version' => 2], $headers)->assertStatus(409);
        $this->patchJson("/api/v1/me/artist/announcements/$row->id", ['version' => 2, 'body' => 'No'])->assertStatus(409);
        $this->deleteJson("/api/v1/me/artist/announcements/$row->id")->assertStatus(409);
        $late = $this->subscriber($artist->id, 'late');
        Announcements::deliver($row->id);
        Announcements::deliver($row->id);
        $this->assertDatabaseCount('announcement_deliveries', 1);
        $this->assertSame(1, query('notifications')->where('type', 'artist_announcement')->count());
        $this->asUser($late)->getJson("/api/v1/me/announcements/$row->id")->assertNotFound();
        $this->asUser($fan)->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.read_at', null);
        $this->getJson("/api/v1/me/announcements/$row->id")->assertOk()->assertJsonPath('data.body', 'Latest news');
        $this->assertSame(null, query('announcement_deliveries')->first()->read_at);
        $this->patchJson("/api/v1/me/announcements/$row->id/read")->assertOk();
        $this->assertNotNull(query('announcement_deliveries')->first()->read_at);
        $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 0);
        $row2 = $this->campaign($owner, 'send-campaign-two');
        Announcements::deliver($row2->id);
        $notice = query('announcement_deliveries')->where('announcement_id', $row2->id)->where('user_id', $fan->id)->first();
        $this->patchJson('/api/v1/notifications/' . $notice->notification_id . '/read')->assertOk();
        $this->assertNotNull(query('announcement_deliveries')->where('id', $notice->id)->first()->read_at);
    }

    public function testUnsubscribeAndGlobalDisableCannotBeUndoneForOldCampaigns(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $fan = $this->subscriber($artist->id);
        $global = $this->subscriber($artist->id, 'global');
        $row = $this->campaign($owner);
        Communications::subscribe($fan, $artist->id, false, null);
        Communications::subscribe($fan, $artist->id, true, config('communications.consent_version'));
        Communications::updatePreferences($global->id, ['artist_updates_enabled' => false]);
        Communications::updatePreferences($global->id, ['artist_updates_enabled' => true]);
        Announcements::deliver($row->id);
        $this->assertSame(2, query('announcement_deliveries')->where('status', 'suppressed')->count());
        $this->assertSame(0, query('notifications')->where('type', 'artist_announcement')->count());
        $fresh = $this->campaign($owner, 'fresh-campaign');
        Announcements::deliver($fresh->id);
        $this->assertSame(2, query('announcement_deliveries')->where('status', 'delivered')->count());
    }

    public function testQuotaCountsAcceptedBroadcastsAndOversizedAudienceConsumesNothing(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $this->subscriber($artist->id);
        $this->subscriber($artist->id, 'second');
        $this->app->mergeConfig(['artists' => ['plans' => ['artist_pro' => ['announcement_recipients' => 1]]]]);
        $row = Announcements::create($owner, ['title' => 'Hello', 'body' => 'World']);
        $this->asUser($owner)->postJson("/api/v1/me/artist/announcements/$row->id/send", ['version' => 1], ['Idempotency-Key' => 'over-audience'])->assertStatus(422);
        $this->assertDatabaseCount('announcement_deliveries', 0);
        $this->assertDatabaseCount('communication_idempotency', 0);
        $this->assertSame('draft', query('announcements')->first()->status);
        $this->app->mergeConfig(['artists' => ['plans' => ['artist_pro' => ['announcement_recipients' => 1000]]]]);
        for ($i = 0; $i < 4; $i++) {
            $this->campaign($owner, 'quota-campaign-' . $i);
        }
        $this->postJson("/api/v1/me/artist/announcements/$row->id/send", ['version' => 1], ['Idempotency-Key' => 'fifth-campaign'])->assertTooManyRequests();
        $this->assertSame(4, query('announcements')->whereNotNull('accepted_at')->count());
        $this->assertDatabaseCount('announcement_deliveries', 8);
    }

    public function testBlocksRestrictionsAndExpiredPeriodSuppressPendingDeliveries(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $fan = $this->subscriber($artist->id);
        $blocked = $this->campaign($owner);
        $this->asUser($fan)->postJson('/api/v1/users/' . $owner->id . '/block')->assertCreated();
        Announcements::deliver($blocked->id);
        $this->assertSame('suppressed', query('announcement_deliveries')->first()->status);
        $this->putJson("/api/v1/artists/$artist->id/update-subscription", ['subscribed' => false])->assertOk();
        $this->deleteJson('/api/v1/users/' . $owner->id . '/block')->assertOk();
        Communications::subscribe($fan, $artist->id, true, config('communications.consent_version'));
        $this->assertSame(1, Announcements::audience($artist->id)->count());
        $expired = $this->campaign($owner, 'expired-period');
        query('billing_paid_periods')->where('id', '>', 0)->update(['ends_at' => Billing::timestamp(time() - 1)]);
        Announcements::deliver($expired->id);
        $this->assertSame(2, query('announcement_deliveries')->where('status', 'suppressed')->count());
        $this->assertSame(0, query('notifications')->where('type', 'artist_announcement')->count());
    }

    public function testDeliveredContentHonorsBlocksAndDeletedLinkedVideosAndReports(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $fan = $this->subscriber($artist->id);
        $video = $this->makeVideo($owner);
        $row = Announcements::create($owner, ['title' => 'Release', 'body' => 'Watch this', 'video_id' => $video->id]);
        Announcements::send($owner, $row->id, 1, 'video-campaign');
        Announcements::deliver($row->id);
        $this->asUser($fan)->postJson('/api/v1/reports', ['announcement_id' => $row->id, 'reason' => 'Spam'])->assertCreated();
        $video->remove();
        $this->getJson("/api/v1/me/announcements/$row->id")->assertNotFound();
        $this->getJson('/api/v1/me/announcements')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.data.message', 'This activity is no longer available.');
        $this->assertNotNull(query('reports')->first()->evidence);
    }
    public function testActualThousandRecipientBoundaryNeverTruncatesOrConsumesQuotaOnRejection(): void
    {
        [$owner, $artist] = $this->paidArtist();
        // Bulk, non-login fixtures keep this a practical boundary test rather than 1001 password hashes.
        $users = [];
        $subscriptions = [];
        for ($i = 1; $i <= 1001; $i++) {
            $users[] = ['id' => $i + 100, 'username' => 'audience' . $i, 'email' => 'audience' . $i . '@example.test',
                'email_verified_at' => now(), 'onboarded_at' => now(), 'created_at' => now(), 'updated_at' => now()];
            $subscriptions[] = ['user_id' => $i + 100, 'artist_id' => $artist->id, 'subscribed' => true,
                'consent_version' => config('communications.consent_version'), 'revision' => 1, 'updated_at' => Billing::timestamp()];
        }
        foreach (array_chunk($users, 100) as $chunk) {
            query('users')->insert($chunk);
        }
        foreach (array_chunk($subscriptions, 100) as $chunk) {
            query('artist_update_subscriptions')->insert($chunk);
        }
        $row = Announcements::create($owner, ['title' => 'Boundary', 'body' => 'News']);
        $headers = ['Idempotency-Key' => 'real-audience-boundary'];
        $this->asUser($owner)->postJson("/api/v1/me/artist/announcements/$row->id/send", ['version' => 1], $headers)->assertStatus(422);
        $this->assertDatabaseCount('announcement_deliveries', 0);
        $this->assertDatabaseCount('communication_idempotency', 0);
        query('artist_update_subscriptions')->where('user_id', 1101)->update(['subscribed' => false]);
        $this->postJson("/api/v1/me/artist/announcements/$row->id/send", ['version' => 1], $headers)->assertAccepted()->assertJsonPath('data.recipient_count', 1000);
        $this->assertDatabaseCount('announcement_deliveries', 1000);
        $this->assertDatabaseCount('communication_idempotency', 1);
    }

    public function testPartialDeliveryFailureResumesWithoutDuplicatingSuccessfulRecipients(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $first = $this->subscriber($artist->id);
        $second = $this->subscriber($artist->id, 'second');
        $row = $this->campaign($owner);
        db()->statement("CREATE TRIGGER fail_announcement BEFORE INSERT ON notifications WHEN NEW.user_id = $second->id BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $this->assertThrows(\PDOException::class, fn() => Announcements::deliver($row->id));
        $this->assertSame(1, query('announcement_deliveries')->where('status', 'delivered')->count());
        $this->assertSame(1, query('announcement_deliveries')->where('status', 'pending')->count());
        db()->statement('DROP TRIGGER fail_announcement');
        Communications::subscribe($second, $artist->id, false, null);
        (new \App\Jobs\DeliverAnnouncements())->handle();
        (new \App\Jobs\DeliverAnnouncements())->handle();
        $this->assertSame(1, query('notifications')->where('type', 'artist_announcement')->count());
        $this->assertSame(1, query('announcement_deliveries')->where('status', 'suppressed')->count());
        $this->assertSame('completed', query('announcements')->where('id', $row->id)->value('status'));
        $this->assertSame(1, query('announcements')->whereNotNull('accepted_at')->count());
    }

}
