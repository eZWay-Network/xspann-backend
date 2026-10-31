<?php

namespace Tests\Feature;

use App\Jobs\BillingMaintenance;
use App\Services\Billing\{Billing, Reconciliation, SubscriptionActions};
use Tests\Support\{ArtistFixtures, BillingFixtures};
use Tests\TestCase;

class BillingAuditTest extends TestCase
{
    use ArtistFixtures, BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billingSetup();
    }

    private function purchase(bool $applyInvoice = true): array
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $attempt = $this->checkout();
        $invoice = $this->stripe->complete($attempt->session_id);
        if ($applyInvoice) {
            $this->event('invoice.paid', $invoice);
        }

        return [$owner, $attempt, $invoice, $this->stripe->objects['/invoices/' . $invoice]['charge']];
    }

    private function refund(string $charge, int $amount, string $status = 'succeeded'): void
    {
        // Stripe's pinned Refund schema has no livemode field.
        $this->stripe->objects['/refunds/re_audit'] = [
            'id' => 're_audit', 'object' => 'refund', 'charge' => $charge,
            'currency' => 'usd', 'amount' => $amount, 'status' => $status,
        ];
        $this->stripe->objects['/charges/' . $charge]['amount_refunded'] = $status === 'succeeded' ? $amount : 0;
    }

    public function testRealRefundSchemaRevokesAPaidPeriodWithoutRequiringInventedFields(): void
    {
        [$owner, , , $charge] = $this->purchase();
        $this->refund($charge, 1999);
        $this->event('refund.created', 're_audit');
        $this->assertSame(null, Billing::access($owner->id));
        $this->assertSame(0, query('billing_paid_periods')->first()->boost_credit_cents);
        $this->assertSame('full_period', query('billing_adjustments')->first()->allocation);
    }

    public function testFirstInvoiceReconciliationSeesExistingRefundsAndDisputes(): void
    {
        [$owner, , $invoice, $charge] = $this->purchase(false);
        $this->refund($charge, 1999);
        $this->stripe->objects['/charges/' . $charge]['disputed'] = true;
        $this->stripe->objects['/disputes/dp_audit'] = [
            'id' => 'dp_audit', 'livemode' => false, 'charge' => $charge,
            'currency' => 'usd', 'amount' => 1999, 'status' => 'needs_response',
        ];
        $this->event('invoice.paid', $invoice);
        $this->assertSame(null, Billing::access($owner->id));
        $this->assertDatabaseCount('billing_adjustments', 2);
        $this->assertSame(1, query('billing_paid_periods')->first()->promotion_hold);
        $this->assertSame(0, query('billing_paid_periods')->first()->boost_credit_cents);
    }

    public function testCanceledSubscriptionsWithPaidTimeStillReconcileMissingRefundEvents(): void
    {
        [$owner, $attempt, , $charge] = $this->purchase();
        $subscription = query('billing_subscriptions')->first();
        $this->stripe->objects['/subscriptions/' . $subscription->subscription_id]['status'] = 'canceled';
        $this->event('customer.subscription.deleted', $subscription->subscription_id);
        $this->assertTrue(Billing::access($owner->id) !== null);
        $this->refund($charge, 1999);
        query('billing_checkouts')->where('id', $attempt->id)->update(['updated_at' => Billing::timestamp(time() - 600)]);
        (new BillingMaintenance())->handle();
        $this->assertSame(null, Billing::access($owner->id));
    }

    public function testCanceledSubscriptionsStillReconcileUnspentCreditAfterPaidTimeEnds(): void
    {
        [, $attempt, $invoice, $charge] = $this->purchase(false);
        $remote = &$this->stripe->objects['/invoices/' . $invoice];
        $remote['lines']['data'][0]['period'] = ['start' => time() - 31 * 86400, 'end' => time() - 86400];
        $this->stripe->objects['/subscriptions/' . $remote['subscription']]['status'] = 'canceled';
        $this->event('invoice.paid', $invoice);
        $this->assertSame(500, query('billing_paid_periods')->first()->boost_credit_cents);
        $this->refund($charge, 1999);
        query('billing_checkouts')->where('id', $attempt->id)->update(['updated_at' => Billing::timestamp(time() - 600)]);
        (new BillingMaintenance())->handle();
        $this->assertSame(0, query('billing_paid_periods')->first()->boost_credit_cents);
    }

    public function testRecoveredOpenCheckoutIncludesProviderExpiry(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $this->stripe->loseCheckoutResponse = true;
        $this->postJson('/api/v1/billing/checkout', ['plan_code' => 'artist_pro', 'offer_version' => config('billing.offer_version')], ['Idempotency-Key' => 'audit-lost-checkout'])->assertStatus(503);
        $attempt = query('billing_checkouts')->first();
        query('billing_checkouts')->where('id', $attempt->id)->update(['updated_at' => Billing::timestamp(time() - 600)]);
        (new BillingMaintenance())->handle();
        $recovered = query('billing_checkouts')->first();
        $expiry = $this->stripe->objects['/checkout/sessions/' . $recovered->session_id]['expires_at'];
        $this->getJson('/api/v1/billing/checkouts/' . $attempt->reference)->assertOk()
            ->assertJsonPath('data.expires_at', gmdate('Y-m-d\TH:i:s\Z', $expiry));
    }

    public function testKnownSubscriptionMetadataIsNotTheOwnershipAuthority(): void
    {
        [$owner] = $this->purchase();
        $subscription = query('billing_subscriptions')->first();
        $this->stripe->objects['/subscriptions/' . $subscription->subscription_id]['metadata'] = [];
        $this->stripe->objects['/subscriptions/' . $subscription->subscription_id]['status'] = 'canceled';
        $this->event('customer.subscription.deleted', $subscription->subscription_id);
        $this->getJson('/api/v1/me/subscription')->assertOk()->assertJsonPath('data.status', 'canceled');
        $this->assertTrue(Billing::access($owner->id) !== null);
    }

    public function testUnsupportedProviderPlanChangesCannotPreventStoppingRenewal(): void
    {
        [$owner] = $this->purchase();
        $subscription = query('billing_subscriptions')->first();
        $this->stripe->objects['/subscriptions/' . $subscription->subscription_id]['items']['data'][0]['price']['id'] = 'price_changed_by_operator';
        $this->postJson('/api/v1/billing/subscription/cancel')->assertOk()->assertJsonPath('data.cancel_at_period_end', true);
        $this->postJson('/api/v1/billing/subscription/resume')->assertStatus(409);
        $owner->update(['status' => 'deleting']);
        app(SubscriptionActions::class)->deleteAccount($owner->id);
        $this->assertSame('canceled', query('billing_subscriptions')->first()->status);
        $this->assertTrue(query('billing_customers')->first()->deleted_at !== null);
    }

    public function testStaleCheckoutSnapshotsCannotReattachADeletedOwner(): void
    {
        [$owner, $attempt] = $this->purchase(false);
        $owner->remove();
        $subscription = app(Reconciliation::class)->recoverCheckout($attempt);
        $this->assertSame(null, $subscription->user_id);
        $this->assertSame(null, $subscription->active_key);
    }

    public function testFailedRefundsAreResolvedWithoutAServiceRevocationOrPermanentReviewHold(): void
    {
        [$owner, , , $charge] = $this->purchase();
        $this->refund($charge, 500, 'failed');
        $this->event('refund.failed', 're_audit');
        $this->assertTrue(Billing::access($owner->id) !== null);
        $this->assertSame('no_service_change', query('billing_adjustments')->first()->allocation);
        $this->assertSame(0, query('billing_paid_periods')->first()->promotion_hold);
    }

    public function testUncapturedForeignAndWrongEnvironmentChargesCannotGrantAccess(): void
    {
        [$owner, , $invoice, $charge] = $this->purchase(false);
        $valid = $this->stripe->objects['/charges/' . $charge];
        foreach ([['captured' => false], ['amount_captured' => 0], ['customer' => 'cus_foreign'],
            ['invoice' => 'in_foreign'], ['livemode' => true], ['currency' => 'eur']] as $change) {
            $this->stripe->objects['/charges/' . $charge] = [...$valid, ...$change];
            $this->rejectInvoice($invoice);
            $this->assertSame(null, Billing::access($owner->id));
        }
        $this->stripe->objects['/charges/' . $charge] = $valid;
        $this->event('invoice.paid', $invoice);
        $this->assertTrue(Billing::access($owner->id) !== null);
    }

    private function rejectInvoice(string $invoice): void
    {
        try {
            $this->event('invoice.paid', $invoice);
            $this->fail('Invalid settlement granted access.');
        } catch (\RuntimeException $exception) {
            $this->assertTrue(str_contains($exception->getMessage(), 'reconciliation failed'));
        }
        $this->assertDatabaseCount('billing_invoices', 0);
        $this->assertDatabaseCount('billing_paid_periods', 0);
        $this->assertDatabaseCount('billing_adjustments', 0);
    }

    public function testInvalidOrIncompleteAdjustmentSnapshotsCannotCommitAPaidGrant(): void
    {
        [, , $invoice, $charge] = $this->purchase(false);
        $this->refund($charge, 500);
        $this->stripe->objects['/refunds/re_audit']['currency'] = 'eur';
        $this->rejectInvoice($invoice);
        $this->stripe->objects['/refunds/re_audit']['currency'] = 'usd';
        $this->stripe->objects['/charges/' . $charge]['amount_refunded'] = 1999;
        $this->rejectInvoice($invoice);
    }

    public function testPaginatedPartialRefundsRevokeTheEntirePeriodWhenTheyAddUp(): void
    {
        [$owner, , $invoice, $charge] = $this->purchase(false);
        $this->refund($charge, 999);
        $this->stripe->objects['/refunds/re_second'] = [
            ...$this->stripe->objects['/refunds/re_audit'], 'id' => 're_second', 'amount' => 1000,
        ];
        $this->stripe->objects['/charges/' . $charge]['amount_refunded'] = 1999;
        $this->stripe->listPageSize = 1;
        $this->event('invoice.paid', $invoice);
        $this->assertSame(null, Billing::access($owner->id));
        $this->assertDatabaseCount('billing_adjustments', 2);
        $this->assertSame(0, query('billing_paid_periods')->first()->boost_credit_cents);
    }

    public function testAnEarlierRefundReadCannotOverwriteTheNewerSettlementSnapshot(): void
    {
        [$owner, $attempt, $invoice, $charge] = $this->purchase(false);
        $this->refund($charge, 1999);
        $earlier = [...$this->stripe->objects['/refunds/re_audit'], 'status' => 'pending'];
        $service = app(Reconciliation::class);
        $subscription = $service->recoverCheckout($attempt);
        $service->invoice($subscription, $invoice, ['kind' => 'refund', 'object' => $earlier]);
        $this->assertSame(null, Billing::access($owner->id));
        $this->assertSame('succeeded', query('billing_adjustments')->first()->status);
    }

    public function testLostCustomerDeletionReplyRecoversFromTheDeletedProviderObject(): void
    {
        [$owner] = $this->purchase();
        $owner->update(['status' => 'deleting']);
        $this->stripe->loseCustomerDeleteResponse = true;
        try {
            app(SubscriptionActions::class)->deleteAccount($owner->id);
            $this->fail('The simulated lost reply was not raised.');
        } catch (\Spark\Testing\ResponseException $exception) {
            $this->assertSame(503, $exception->response->getStatusCode());
        }
        $this->assertSame(null, query('billing_customers')->first()->deleted_at);
        $this->stripe->keys = [];
        app(SubscriptionActions::class)->deleteAccount($owner->id);
        $this->assertTrue(query('billing_customers')->first()->deleted_at !== null);
        $deletes = array_filter($this->stripe->calls, fn($call) => $call['method'] === 'DELETE' && str_starts_with($call['path'], '/customers/'));
        $this->assertCount(1, $deletes);
    }

    public function testLateDeletedOwnerSubscriptionIsStoppedEvenWhenItsInvoiceIsInvalid(): void
    {
        [$owner, , $invoice] = $this->purchase(false);
        $owner->remove();
        $this->stripe->objects['/invoices/' . $invoice]['lines']['data'][0]['price']['id'] = 'price_invalid';
        $this->rejectInvoice($invoice);
        $subscription = query('billing_subscriptions')->first();
        $this->assertSame(null, $subscription->user_id);
        $this->assertSame('canceled', $subscription->status);
        $this->assertTrue($subscription->deletion_requested_at !== null);
    }
}
