<?php

namespace Tests\Feature;

use App\Http\Resources\CommunicationResource;
use App\Jobs\ProcessPrivacyRequest;
use App\Models\Report;
use App\Services\{AccountSecurity, Announcements, CommunicationPrivacy, Communications, Conversations, Moderation, PrivacyRequests, Totp};
use App\Services\Billing\Billing;
use Spark\Facades\Hash;
use Tests\Support\CommunicationFixtures;
use Tests\TestCase;

class CommunicationLifecycleTest extends TestCase
{
    use CommunicationFixtures;

    public function testPopulatedStepThreeUpgradePreservesAccountsFollowsNotificationsAndDefaults(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $artist = $this->makeArtist($a);
        $this->mutual($a, $b);
        $migration = require dirname(__DIR__, 2) . '/database/migrations/migration_2026_10_31_180000_communications.php';
        $migration->down();
        query('notifications')->insert(['user_id' => $b->id, 'type' => 'legacy', 'data' => '{"message":"Existing notice"}', 'created_at' => now()]);
        $this->app->withQueue([job(\App\Jobs\PublishReleases::class)->repeatEveryMinutes(1)->onQueue('releases'), job(\App\Jobs\PruneUploads::class)->repeatDaily()]);
        \Spark\Facades\Queue::connection();
        $this->assertSame(2, query('jobs')->where('queue', 'default')->count());
        $migration->up();
        $this->assertSame(1, query('jobs')->where('queue', 'releases')->count());
        $this->assertSame(1, query('jobs')->where('queue', 'default')->count());
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('follows', 2);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('artist_update_subscriptions', 0);
        $this->assertFalse(Communications::subscription($b->id, $artist->id)['subscribed']);
        $this->assertTrue(Communications::preferences($b->id)['allow_direct_messages']);
        $this->assertSame([], db()->getPdo()->query('PRAGMA foreign_key_check')->fetchAll());
        $this->asUser($b)->getJson('/api/v1/notifications')->assertOk()->assertJsonPath('data.0.data.message', 'Existing notice');
    }

    public function testExportAndDeletionCoverBothParticipantsWithoutErasingReportEvidence(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $conversation = Conversations::open($a->id, $b->id);
        [$message] = Conversations::send($conversation->id, $a->id, 'Report evidence survives', $this->messageInput()['client_message_id']);
        Conversations::send($conversation->id, $b->id, 'Reply from other participant', $this->messageInput()['client_message_id']);
        $report = Moderation::report($b, ['message_id' => $message->id, 'reason' => 'Review']);
        $this->asUser($a);
        $proof = $this->postJson('/api/v1/auth/reauthenticate', ['purpose' => 'export_data', 'password' => 'password123'])->assertOk()->json('data.proof');
        $id = $this->postJson('/api/v1/me/export-requests', ['proof' => $proof])->assertAccepted()->json('data.id');
        (new ProcessPrivacyRequest($id))->handle();
        $raw = storage('local')->get(PrivacyRequests::exportPath($id));
        $this->assertFalse(str_contains($raw, 'Reply from other participant'));
        $export = json_decode(Hash::decrypt($raw), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $export['communications']['messages']);
        $this->assertSame('Reply from other participant', $export['communications']['messages'][1]['body']);
        $this->assertFalse(str_contains(json_encode($export['communications']), $b->email));
        $proof = $this->postJson('/api/v1/auth/reauthenticate', ['purpose' => 'delete_account', 'password' => 'password123'])->assertOk()->json('data.proof');
        $this->postJson('/api/v1/me/deletion-requests', ['proof' => $proof])->assertAccepted();
        $deletion = query('privacy_requests')->where('kind', 'deletion')->first();
        $this->assertSame(0, query('jwt_access_tokens')->where('user_id', $a->id)->count());
        (new ProcessPrivacyRequest($deletion->id))->handle();
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
        $this->assertSame(0, query('notifications')->where('type', 'direct_message')->count());
        $this->assertSame(null, $report->refresh()->message_id);
        $this->assertSame('Report evidence survives', json_decode($report->evidence, true)['body']);
        $this->assertSame([], db()->getPdo()->query('PRAGMA foreign_key_check')->fetchAll());
    }

    public function testRetentionPrunesSharedContentButKeepsAuthorizedReportedSnapshot(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $fan = $this->subscriber($artist->id);
        $this->mutual($owner, $fan);
        $conversation = Conversations::open($owner->id, $fan->id);
        [$message] = Conversations::send($conversation->id, $owner->id, 'Old private message', $this->messageInput()['client_message_id']);
        $report = Moderation::report($fan, ['message_id' => $message->id, 'reason' => 'Retain evidence']);
        $announcement = $this->campaign($owner);
        Announcements::deliver($announcement->id);
        $old = Billing::timestamp(time() - 366 * 86400);
        foreach (['messages', 'announcements', 'communication_idempotency', 'communication_consent_history', 'realtime_outbox'] as $table) {
            query($table)->where('id', '>', 0)->update(['created_at' => $old]);
        }
        query('announcements')->where('id', $announcement->id)->update(['accepted_at' => $old]);
        CommunicationPrivacy::maintain();
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('announcements', 0);
        $this->assertDatabaseCount('announcement_deliveries', 0);
        $this->assertDatabaseCount('communication_idempotency', 0);
        $this->assertSame(0, query('realtime_outbox')->where('created_at', $old)->count());
        $this->assertSame(2, query('realtime_outbox')->where('type', 'conversation.updated')->count());
        $this->assertDatabaseCount('artist_update_subscriptions', 1);
        $this->assertSame('Old private message', json_decode($report->refresh()->evidence, true)['body']);
    }

    public function testIndependentStaffCanOnlyReadReportedMessageEvidenceWithAudit(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $staff = $this->makeUser('staff');
        $this->mutual($a, $b);
        $conversation = Conversations::open($a->id, $b->id);
        [$message] = Conversations::send($conversation->id, $a->id, 'Reported content', $this->messageInput()['client_message_id']);
        Conversations::send($conversation->id, $a->id, 'Unreported private content', $this->messageInput()['client_message_id']);
        $report = Moderation::report($b, ['message_id' => $message->id, 'reason' => 'Review']);
        $secret = Totp::secret();
        AccountSecurity::enrollStaff($staff, 'moderator', $secret, Totp::code($secret, intdiv(time(), 30) - 1));
        $this->asUser($staff)->getJson('/api/v1/admin/reports/' . $report->id)->assertForbidden();
        $this->postJson('/api/v1/auth/reauthenticate', ['purpose' => 'staff', 'password' => 'password123', 'mfa_code' => Totp::code($secret, intdiv(time(), 30))])->assertOk();
        $response = $this->getJson('/api/v1/admin/reports/' . $report->id)->assertOk()
            ->assertHeader('Cache-Control', 'no-store')->assertJsonPath('data.evidence.body', 'Reported content');
        $this->assertFalse(str_contains($response->content(), 'Unreported private content'));
        $this->getJson("/api/v1/conversations/$conversation->id/messages")->assertNotFound();
        $this->assertDatabaseHas('audit_events', ['action' => 'moderation.evidence_viewed']);
        $this->postJson('/api/v1/admin/reports/' . $report->id . '/decision', ['action' => 'restrict', 'reason' => 'Restricted message', 'version' => 1])->assertOk();
        $this->asUser($b)->getJson("/api/v1/conversations/$conversation->id/messages?after_id=0")->assertOk()
            ->assertJsonPath('data.0.body', null)->assertJsonPath('data.0.unavailable', true)
            ->assertJsonPath('data.1.body', 'Unreported private content');
    }

    public function testListsHaveBoundedQueriesAndResourcesDoNotQuery(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $fan = $this->subscriber($artist->id);
        for ($i = 0; $i < 8; $i++) {
            $peer = $this->makeUser('peer' . $i);
            $this->mutual($owner, $peer);
            $conversation = Conversations::open($owner->id, $peer->id);
            Conversations::send($conversation->id, $peer->id, 'Last message', $this->messageInput()['client_message_id']);
            Announcements::create($owner, ['title' => 'Draft ' . $i, 'body' => 'Some news']);
        }
        $this->asUser($owner)->getJson('/api/v1/conversations')->assertOk();
        $this->app->mergeConfig(['app' => ['debug' => true]]);
        $queries = 0;
        event()->addListener('app:db.queryExecuted', function () use (&$queries): void { $queries++; });
        foreach (['/conversations', '/me/artist/announcements', '/notifications'] as $path) {
            $queries = 0;
            $this->getJson('/api/v1' . $path . '?limit=1')->assertOk();
            $small = $queries;
            $queries = 0;
            $this->getJson('/api/v1' . $path . '?limit=8')->assertOk()->assertJsonCount(8, 'data');
            $this->assertTrue($small > 0 && $small <= 12);
            $this->assertSame($small, $queries);
        }
        $rows = Conversations::hydrate(Conversations::inboxQuery($owner->id)->get()->all());
        $drafts = Announcements::summaries(query('announcements')->where('user_id', $owner->id)->get()->all());
        $queries = 0;
        foreach ($rows as $row) {
            CommunicationResource::conversation($row, $owner->id);
        }
        foreach ($drafts as $draft) {
            CommunicationResource::announcement($draft, true);
        }
        $this->assertSame(0, $queries);
    }
}
