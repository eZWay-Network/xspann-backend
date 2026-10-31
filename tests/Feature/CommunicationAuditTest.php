<?php

namespace Tests\Feature;

use App\Jobs\ProcessVideo;
use App\Models\Video;
use App\Services\{Announcements, CommunicationPrivacy, Conversations, Moderation, VideoRetry};
use App\Services\Billing\Billing;
use Tests\Support\CommunicationFixtures;
use Tests\TestCase;

class CommunicationAuditTest extends TestCase
{
    use CommunicationFixtures;

    public function testAnnouncementLinksCannotBypassValidationWithPunctuationOrQueryStrings(): void
    {
        [$owner] = $this->paidArtist();
        $this->asUser($owner);

        foreach (['example.com?offer=1', 'example.com#offer', '(example.com)', 'example.com.', 'example.com,', 'artist@example.com!', 'example.xn--p1ai', 'example。com', '127.0.0.1/path'] as $body) {
            $this->postJson('/api/v1/me/artist/announcements', ['title' => 'News', 'body' => $body])->assertStatus(422);
        }

        $this->assertDatabaseCount('announcements', 0);
        $this->postJson('/api/v1/me/artist/announcements', ['title' => 'News', 'body' => 'New music arrives at 8.30. Thank you!'])->assertCreated();
    }

    public function testRetentionRemovesOnlyExpiredMessageAlertsAndInvalidatesTheConversation(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $conversation = Conversations::open($a->id, $b->id);
        [$expired] = Conversations::send($conversation->id, $a->id, 'Expired', $this->messageInput()['client_message_id']);
        [$current] = Conversations::send($conversation->id, $a->id, 'Current', $this->messageInput()['client_message_id']);
        $report = Moderation::report($b, ['message_id' => $expired->id, 'reason' => 'Retain evidence']);
        query('messages')->where('id', $expired->id)->update(['created_at' => Billing::timestamp(time() - 366 * 86400)]);
        $version = (int) query('conversations')->where('id', $conversation->id)->value('version');

        CommunicationPrivacy::maintain();

        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame($current->id, \App\Models\Notification::first()->data['message_id']);
        $this->assertSame('Expired', json_decode($report->refresh()->evidence, true)['body']);
        $this->assertSame($version + 1, query('conversations')->where('id', $conversation->id)->value('version'));
        $this->asUser($b)->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.unread_count', 1);
        $this->getJson('/api/v1/conversations')->assertOk()->assertJsonPath('meta.unread_count', 1);
    }

    public function testEncodingFailureAndRetryEmitMonotonicCommittedReleaseHints(): void
    {
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner, ['status' => Video::STATUS_PROCESSING]);
        $version = $video->version;

        (new ProcessVideo($video->id))->failed(new \RuntimeException('Private internal encoder failure'));
        (new ProcessVideo($video->id))->failed(new \RuntimeException('Duplicate callback'));
        $this->assertSame($version + 1, $video->refresh()->version);
        $this->assertSame(Video::STATUS_FAILED, $video->status);
        $this->assertSame(1, query('realtime_outbox')->where('type', 'release.updated')->count());

        $this->assertTrue(VideoRetry::retry($video->id));
        $this->assertSame($version + 2, $video->refresh()->version);
        $this->assertSame(Video::STATUS_PROCESSING, $video->status);
        $events = query('realtime_outbox')->where('type', 'release.updated')->orderBy('id')->get()->all();
        $this->assertSame([$version + 1, $version + 2], array_column($events, 'version'));
        $this->assertFalse(str_contains(json_encode($events), 'Private internal encoder failure'));

        $video->update(['status' => Video::STATUS_DELETED]);
        (new ProcessVideo($video->id))->failed(new \RuntimeException('Late callback'));
        $this->assertSame(Video::STATUS_DELETED, $video->refresh()->status);
        $this->assertSame(2, query('realtime_outbox')->where('type', 'release.updated')->count());
    }

    public function testDocumentedTextLimitsCountUnicodeCharactersInsteadOfBytes(): void
    {
        [$owner] = $this->paidArtist();
        $peer = $this->makeUser('peer');
        $this->mutual($owner, $peer);
        $conversation = Conversations::open($owner->id, $peer->id);
        $body = str_repeat('🎵', 2000);
        $this->asUser($owner)->postJson("/api/v1/conversations/$conversation->id/messages", $this->messageInput($body))
            ->assertCreated()->assertJsonPath('data.body', $body);
        $this->postJson("/api/v1/conversations/$conversation->id/messages", $this->messageInput($body . '🎵'))->assertStatus(422);

        $title = str_repeat('音', 120);
        $this->postJson('/api/v1/me/artist/announcements', ['title' => $title, 'body' => $body])
            ->assertCreated()->assertJsonPath('data.title', $title)->assertJsonPath('data.body', $body);
        $this->postJson('/api/v1/me/artist/announcements', ['title' => $title . '音', 'body' => $body])->assertStatus(422);
        $this->postJson('/api/v1/me/artist/announcements', ['title' => $title, 'body' => $body . '🎵'])->assertStatus(422);
    }

    public function testRecentlyAcceptedOldDraftSurvivesRetentionAndStillConsumesQuota(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $this->subscriber($artist->id);
        $draft = Announcements::create($owner, ['title' => 'Saved draft', 'body' => 'News']);
        query('announcements')->where('id', $draft->id)->update(['created_at' => Billing::timestamp(time() - 366 * 86400)]);
        Announcements::send($owner, $draft->id, 1, 'old-draft-send');
        for ($i = 0; $i < 3; $i++) {
            $this->campaign($owner, 'current-campaign-' . $i);
        }

        CommunicationPrivacy::maintain();

        $this->assertSame(4, query('announcements')->whereNotNull('accepted_at')->count());
        Announcements::deliver($draft->id);
        $this->assertDatabaseHas('announcement_deliveries', ['announcement_id' => $draft->id, 'status' => 'delivered']);
        $next = Announcements::create($owner, ['title' => 'Fifth', 'body' => 'No extra quota']);
        $this->asUser($owner)->postJson("/api/v1/me/artist/announcements/$next->id/send", ['version' => 1], ['Idempotency-Key' => 'fifth-after-retention'])->assertTooManyRequests();
    }

    public function testOmittedVideoSurvivesAnEditAndExplicitNullClearsIt(): void
    {
        [$owner] = $this->paidArtist();
        $video = $this->makeVideo($owner);
        $this->asUser($owner)->postJson('/api/v1/me/artist/announcements', ['title' => 'Release', 'body' => 'Music', 'video_id' => $video->id])->assertCreated();
        $id = query('announcements')->first()->id;
        $this->patchJson("/api/v1/me/artist/announcements/$id", ['version' => 1, 'title' => 'Updated release'])
            ->assertOk()->assertJsonPath('data.video_id', $video->id);
        $this->patchJson("/api/v1/me/artist/announcements/$id", ['version' => 2, 'video_id' => null])
            ->assertOk()->assertJsonPath('data.video_id', null);
    }

    public function testRefundStopsRemainingDeliveryAndReplayPreservesTheAcceptedResult(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $first = $this->subscriber($artist->id);
        $second = $this->subscriber($artist->id, 'second');
        $campaign = $this->campaign($owner, 'refund-campaign');
        db()->statement("CREATE TRIGGER stop_second BEFORE INSERT ON notifications WHEN NEW.user_id = $second->id BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $this->assertThrows(\PDOException::class, fn() => Announcements::deliver($campaign->id));
        db()->statement('DROP TRIGGER stop_second');

        $invoice = query('billing_invoices')->first();
        $this->stripe->objects['/refunds/re_communication'] = [
            'id' => 're_communication', 'charge' => $invoice->charge_id,
            'currency' => 'usd', 'amount' => $invoice->amount_paid, 'status' => 'succeeded',
        ];
        $this->stripe->objects['/charges/' . $invoice->charge_id]['amount_refunded'] = $invoice->amount_paid;
        $this->event('refund.created', 're_communication');
        $this->assertSame(null, Billing::access($owner->id));
        Announcements::deliver($campaign->id);
        Announcements::deliver($campaign->id);

        $this->assertDatabaseHas('announcement_deliveries', ['announcement_id' => $campaign->id, 'user_id' => $first->id, 'status' => 'delivered']);
        $this->assertDatabaseHas('announcement_deliveries', ['announcement_id' => $campaign->id, 'user_id' => $second->id, 'status' => 'suppressed']);
        $this->assertSame(1, query('notifications')->where('type', 'artist_announcement')->count());
        $this->asUser($first)->getJson("/api/v1/me/announcements/$campaign->id")->assertOk();
        $this->asUser($owner)->postJson("/api/v1/me/artist/announcements/$campaign->id/send", ['version' => 1], ['Idempotency-Key' => 'refund-campaign'])
            ->assertAccepted()->assertJsonPath('data.recipient_count', 2)->assertJsonPath('data.status', 'queued');
        $this->assertDatabaseCount('communication_idempotency', 1);
    }

    public function testFailedOutboxInsertRollsBackEncodingStateAndAllowsRecovery(): void
    {
        $owner = $this->makeUser();
        $video = $this->makeVideo($owner, ['status' => Video::STATUS_PROCESSING]);
        $job = new ProcessVideo($video->id);
        db()->statement("CREATE TRIGGER stop_hint BEFORE INSERT ON realtime_outbox BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
        $this->assertThrows(\PDOException::class, fn() => $job->failed(new \RuntimeException('Encoder failure')));
        $this->assertSame(Video::STATUS_PROCESSING, $video->refresh()->status);
        $this->assertSame(1, $video->version);
        $this->assertDatabaseCount('realtime_outbox', 0);
        db()->statement('DROP TRIGGER stop_hint');
        $job->failed(new \RuntimeException('Encoder failure'));
        $this->assertSame(Video::STATUS_FAILED, $video->refresh()->status);
        $this->assertDatabaseCount('realtime_outbox', 1);
    }

    public function testReadAllAndClearSynchronizeOnlyTheCurrentFansDeliveries(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $fan = $this->subscriber($artist->id);
        $other = $this->subscriber($artist->id, 'other');
        $campaign = $this->campaign($owner);
        Announcements::deliver($campaign->id);
        $this->asUser($fan)->patchJson('/api/v1/notifications/read-all')->assertOk();
        $this->assertNotNull(query('announcement_deliveries')->where('user_id', $fan->id)->value('read_at'));
        $this->assertSame(null, query('announcement_deliveries')->where('user_id', $other->id)->value('read_at'));
        $this->deleteJson('/api/v1/notifications/clear-all')->assertOk();
        $this->getJson("/api/v1/me/announcements/$campaign->id")->assertOk();
        $this->patchJson("/api/v1/me/announcements/$campaign->id/read")->assertOk();
        $this->assertDatabaseCount('announcement_deliveries', 2);
        $this->assertSame(null, query('announcement_deliveries')->where('user_id', $fan->id)->value('notification_id'));
        $this->assertSame(1, query('notifications')->where('type', 'artist_announcement')->count());
    }
}
