<?php

namespace Tests\Feature;

use App\Jobs\{BillingMaintenance, ProcessPrivacyRequest};
use App\Services\Billing\{Billing, Retention, Webhooks};
use Nyholm\Psr7\ServerRequest;
use Spark\Http\Request;
use Spark\Testing\TestResponse;
use Tests\Support\{ArtistFixtures, BillingFixtures};
use Tests\TestCase;

class BillingRecoveryTest extends TestCase
{
    use ArtistFixtures, BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billingSetup();
    }

    private function rawWebhook(string $body, string $signature): TestResponse
    {
        $request = new ServerRequest('POST', 'http://localhost/api/v1/webhooks/stripe', [
            'Content-Type' => 'application/json', 'Accept' => 'application/json', 'Stripe-Signature' => $signature,
        ], $body);
        $response = $this->app->handle(Request::fromPsrRequest($request));
        $this->app->terminate();

        return new TestResponse($response);
    }

    public function testRawSignaturesRotationTimestampEnvironmentAndSizeAreEnforcedBeforeReceipt(): void
    {
        $event = ['id' => 'evt_signed', 'type' => 'unknown.event', 'created' => time(), 'livemode' => false,
            'api_version' => '2024-06-20', 'data' => ['object' => ['id' => 'obj_test']]];
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $time = time();
        $signature = 't=' . $time . ',v1=bad,v1=' . hash_hmac('sha256', "$time.$body", 'whsec_test');
        $this->rawWebhook($body, 't=' . $time . ',v1=forged')->assertStatus(400);
        $this->rawWebhook($body . ' ', $signature)->assertStatus(400);
        $old = $time - 301;
        $this->rawWebhook($body, "t=$old,v1=" . hash_hmac('sha256', "$old.$body", 'whsec_test'))->assertStatus(400);
        $this->rawWebhook(str_repeat('x', 1048577), $signature)->assertStatus(413);
        foreach ([['livemode' => true], ['account' => 'acct_foreign'], ['api_version' => '2025-01-01'], ['context' => 'acct_foreign']] as $change) {
            $invalid = json_encode([...$event, ...$change], JSON_THROW_ON_ERROR);
            $this->rawWebhook($invalid, "t=$time,v1=" . hash_hmac('sha256', "$time.$invalid", 'whsec_test'))->assertStatus(400);
        }
        $this->assertDatabaseCount('billing_webhooks', 0);
        $this->app->mergeConfig(['billing' => ['webhook_secrets' => ['whsec_new', 'whsec_test']]]);
        $this->rawWebhook($body, $signature)->assertOk()->assertJsonPath('received', true);
        $this->rawWebhook($body, $signature)->assertOk();
        $this->assertDatabaseCount('billing_webhooks', 1);
        $record = query('billing_webhooks')->first();
        $this->assertFalse(str_contains($record->payload, 'evt_signed'));
        app(Webhooks::class)->process($record->id);
        $this->assertSame('ignored', query('billing_webhooks')->first()->status);
    }

    public function testReceiptSurvivesDispatchFailureAndMaintenanceRecoversLostCheckoutResponse(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $this->stripe->loseCheckoutResponse = true;
        $this->postJson('/api/v1/billing/checkout', ['plan_code' => 'artist_pro', 'offer_version' => config('billing.offer_version')], ['Idempotency-Key' => 'lost-response-recovery'])->assertStatus(503);
        $attempt = query('billing_checkouts')->first();
        $session = array_values(array_filter($this->stripe->objects, fn($object) => str_starts_with($object['id'], 'cs_')))[0];
        $invoice = $this->stripe->complete($session['id']);
        query('billing_checkouts')->where('id', $attempt->id)->update(['updated_at' => Billing::timestamp(time() - 600), 'request_started_at' => Billing::timestamp(time() - 90000)]);
        (new BillingMaintenance())->handle();
        $this->assertSame('completed', query('billing_checkouts')->first()->status);
        $this->assertSame('artist_pro', Billing::access($owner->id)->plan_code);
        $this->assertDatabaseCount('billing_paid_periods', 1);

        $body = json_encode(['id' => 'evt_queue_failure', 'type' => 'invoice.paid', 'created' => time(), 'livemode' => false,
            'api_version' => '2024-06-20', 'data' => ['object' => ['id' => $invoice]]], JSON_THROW_ON_ERROR);
        $time = time();
        // Rename only the disposable queue table to reproduce a committed inbox/broker failure.
        db()->getPdo()->exec('ALTER TABLE jobs RENAME TO unavailable_jobs');
        try {
            app(Webhooks::class)->receive($body, "t=$time,v1=" . hash_hmac('sha256', "$time.$body", 'whsec_test'));
            $this->assertSame('pending', query('billing_webhooks')->where('event_id', 'evt_queue_failure')->first()->status);
        } finally {
            db()->getPdo()->exec('ALTER TABLE unavailable_jobs RENAME TO jobs');
        }
        $record = query('billing_webhooks')->where('event_id', 'evt_queue_failure')->first();
        query('billing_webhooks')->where('id', $record->id)->update(['updated_at' => Billing::timestamp(time() - 90)]);
        (new BillingMaintenance())->handle();
        $this->assertSame('queued', query('billing_webhooks')->where('id', $record->id)->first()->status);
        app(Webhooks::class)->process($record->id);
        $this->assertDatabaseCount('billing_paid_periods', 1);
    }

    public function testLiveGatePortalConfigurationAndExpiredPendingCheckout(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $input = ['plan_code' => 'artist_pro', 'offer_version' => config('billing.offer_version')];
        $this->app->mergeConfig(['billing' => ['environment' => 'live']]);
        $this->postJson('/api/v1/billing/checkout', $input, ['Idempotency-Key' => 'live-disabled'])->assertStatus(503);
        $this->assertDatabaseCount('billing_checkouts', 0);
        $this->app->mergeConfig(['billing' => ['environment' => 'test']]);
        $attempt = $this->checkout();
        $this->stripe->objects['/checkout/sessions/' . $attempt->session_id]['status'] = 'expired';
        $this->event('checkout.session.expired', $attempt->session_id);
        $this->checkout(key: 'new-after-expiry');
        $this->assertDatabaseCount('billing_checkouts', 2);
        $this->assertDatabaseCount('billing_customers', 1);
        $this->stripe->objects['/billing_portal/configurations/bpc_xpn'] = [
            'id' => 'bpc_xpn', 'livemode' => false, 'active' => true,
            'features' => ['subscription_update' => ['enabled' => true], 'subscription_cancel' => ['enabled' => true, 'mode' => 'at_period_end']],
        ];
        $this->postJson('/api/v1/billing/portal')->assertStatus(503);
        $this->stripe->objects['/billing_portal/configurations/bpc_xpn']['features']['subscription_update']['enabled'] = false;
        $this->postJson('/api/v1/billing/portal')->assertOk()->assertJsonPath('data.url', 'https://billing.stripe.com/p/session/own');
        $this->postJson('/api/v1/billing/portal', ['customer_id' => 'cus_foreign'])->assertStatus(422);
    }

    public function testDeletionStopsRenewalBeforeRemovingIdentityAndRetentionPurgesExpiredFinancialReferences(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $attempt = $this->checkout();
        $invoice = $this->stripe->complete($attempt->session_id);
        $this->event('invoice.paid', $invoice);
        $proof = $this->postJson('/api/v1/auth/reauthenticate', ['purpose' => 'delete_account', 'password' => 'password123'])->assertOk()->json('data.proof');
        $this->postJson('/api/v1/me/deletion-requests', ['proof' => $proof])->assertStatus(202);
        $this->assertSame(null, Billing::access($owner->id));
        $record = query('privacy_requests')->where('kind', 'deletion')->first();
        (new ProcessPrivacyRequest($record->id))->handle();
        $this->assertDatabaseCount('users', 0);
        $subscription = query('billing_subscriptions')->first();
        $this->assertSame(null, $subscription->user_id);
        $this->assertSame('canceled', $subscription->status);
        $this->assertSame(null, query('billing_checkouts')->first()->payload);
        $this->assertSame(null, query('billing_customers')->first()->user_id);
        $this->assertDatabaseCount('billing_invoices', 1);
        Retention::run();
        $this->assertDatabaseCount('billing_invoices', 1);
        query('billing_customers')->where('id', '>', 0)->update(['deleted_at' => '2000-01-01 00:00:00']);
        Retention::run();
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('billing_paid_periods', 0);
        $this->assertDatabaseCount('billing_subscriptions', 0);
        $this->assertDatabaseCount('billing_checkouts', 0);
        $this->assertDatabaseCount('billing_customers', 0);
    }
    public function testLostCustomerAndRenewalResponsesRecoverWithoutRepeatingExpiredCreationKeys(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $this->stripe->loseCustomerResponse = true;
        $input = ['plan_code' => 'artist_pro', 'offer_version' => config('billing.offer_version')];
        $this->postJson('/api/v1/billing/checkout', $input, ['Idempotency-Key' => 'customer-timeout'])->assertStatus(503);
        $this->assertSame(null, query('billing_customers')->first()->customer_id);
        query('billing_customers')->where('id', '>', 0)->update(['request_started_at' => Billing::timestamp(time() - 90000)]);
        $attempt = $this->checkout(key: 'customer-timeout');
        $customerPosts = array_filter($this->stripe->calls, fn($call) => $call['method'] === 'POST' && $call['path'] === '/customers');
        $this->assertCount(1, $customerPosts);
        $this->assertDatabaseCount('billing_customers', 1);
        $this->assertDatabaseCount('billing_checkouts', 1);
        $invoice = $this->stripe->complete($attempt->session_id);
        $this->event('invoice.paid', $invoice);
        $this->stripe->loseRenewalResponse = true;
        $this->postJson('/api/v1/billing/subscription/cancel')->assertStatus(503);
        $this->assertTrue(query('billing_subscriptions')->first()->renewal_operation !== null);
        query('billing_subscriptions')->where('id', '>', 0)->update(['renewal_started_at' => Billing::timestamp(time() - 90000)]);
        query('billing_checkouts')->where('id', '>', 0)->update(['updated_at' => Billing::timestamp(time() - 600)]);
        (new BillingMaintenance())->handle();
        $this->getJson('/api/v1/me/subscription')->assertOk()->assertJsonPath('data.renewal_pending', false)->assertJsonPath('data.cancel_at_period_end', true);
        $renewalPosts = array_filter($this->stripe->calls, fn($call) => $call['method'] === 'POST' && str_starts_with($call['path'], '/subscriptions/'));
        $this->assertCount(1, $renewalPosts);
        $this->assertSame('artist_pro', Billing::access($owner->id)->plan_code);
    }

    public function testSuspendedOwnersCanStopRenewalAndReadBillingButCannotStartNewCharges(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $attempt = $this->checkout();
        $invoice = $this->stripe->complete($attempt->session_id);
        $this->event('invoice.paid', $invoice);
        $owner->update(['status' => 'suspended']);
        $token = $this->flushHeaders()->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'password123'])
            ->assertOk()->assertJsonPath('data.restricted_access', true)->json('data.token');
        $this->withToken($token)->getJson('/api/v1/me/subscription')->assertOk();
        $this->getJson('/api/v1/me/billing/invoices')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/billing/checkouts/' . $attempt->reference)->assertOk();
        $this->postJson('/api/v1/billing/subscription/cancel')->assertOk()->assertJsonPath('data.cancel_at_period_end', true);
        $this->postJson('/api/v1/billing/subscription/resume')->assertStatus(403);
        $this->postJson('/api/v1/billing/checkout', ['plan_code' => 'artist_pro', 'offer_version' => config('billing.offer_version')], ['Idempotency-Key' => 'suspended-purchase'])->assertStatus(403);
        $this->stripe->objects['/billing_portal/configurations/bpc_xpn'] = [
            'id' => 'bpc_xpn', 'livemode' => false, 'active' => true,
            'features' => ['subscription_update' => ['enabled' => false], 'subscription_cancel' => ['enabled' => true, 'mode' => 'at_period_end']],
        ];
        $this->postJson('/api/v1/billing/portal')->assertStatus(403);
        $this->assertSame(null, Billing::access($owner->id));
    }

}
