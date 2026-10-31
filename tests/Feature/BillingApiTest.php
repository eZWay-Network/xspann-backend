<?php

namespace Tests\Feature;

use App\Services\Billing\{Adjustments, Billing, Webhooks};
use Tests\Support\{ArtistFixtures, BillingFixtures};
use Tests\TestCase;

class BillingApiTest extends TestCase
{
    use ArtistFixtures, BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billingSetup();
    }

    public function testCheckoutIsDurableIdempotentAndCannotChooseServerOwnedFields(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $input = ['plan_code' => 'artist_pro', 'offer_version' => config('billing.offer_version')];
        $this->postJson('/api/v1/billing/checkout', $input)->assertStatus(422);
        foreach (['customer', 'price', 'return_url', 'user_id', 'platform'] as $field) {
            $this->postJson('/api/v1/billing/checkout', [...$input, $field => 'forged'], ['Idempotency-Key' => 'test-key-' . $field])->assertStatus(422);
        }
        cache('throttle')->flush();
        $this->stripe->loseCheckoutResponse = true;
        $this->postJson('/api/v1/billing/checkout', $input, ['Idempotency-Key' => 'lost-response'])->assertStatus(503);
        $this->assertDatabaseCount('billing_checkouts', 1);
        $this->assertSame(null, query('billing_checkouts')->first()->session_id);
        $this->postJson('/api/v1/billing/checkout', $input, ['Idempotency-Key' => 'different-key'])->assertStatus(409);
        $attempt = $this->checkout(key: 'lost-response');
        $this->assertTrue(str_starts_with($attempt->session_id, 'cs_test_'));
        $this->checkout(key: 'lost-response');
        $this->assertDatabaseCount('billing_checkouts', 1);
        $this->postJson('/api/v1/billing/checkout', ['plan_code' => 'artist_starter', 'offer_version' => config('billing.offer_version')], ['Idempotency-Key' => 'lost-response'])->assertStatus(409);
        $this->getJson('/api/v1/me/entitlements')->assertOk()->assertJsonPath('data.capabilities.plan', 'free');
        $this->asUser($this->makeUser('other'))->getJson('/api/v1/billing/checkouts/' . $attempt->reference)->assertStatus(404);
        $this->assertFalse(str_contains($attempt->payload, 'success_url'));
    }

    public function testPaidInvoiceAloneGrantsAccessAndReplayDoesNotDuplicatePeriods(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $attempt = $this->checkout();
        $invoice = $this->stripe->complete($attempt->session_id, false);
        $this->event('checkout.session.completed', $attempt->session_id);
        $this->getJson('/api/v1/me/entitlements')->assertOk()->assertJsonPath('data.capabilities.plan', 'free');
        $remote = &$this->stripe->objects['/invoices/' . $invoice];
        $remote['paid'] = true;
        $remote['status'] = 'paid';
        $remote['amount_paid'] = 1999;
        $remote['amount_remaining'] = 0;
        $remote['status_transitions']['paid_at'] = time();
        $charge = &$this->stripe->objects['/charges/' . $remote['charge']];
        $charge['paid'] = true;
        $charge['captured'] = true;
        $charge['amount_captured'] = 1999;
        $this->event('invoice.paid', $invoice, 'evt_paid');
        $this->event('invoice.paid', $invoice, 'evt_paid');
        $this->event('invoice.paid', $invoice);
        $this->assertDatabaseCount('billing_paid_periods', 1);
        $this->assertSame(500, query('billing_paid_periods')->first()->boost_credit_cents);
        $this->getJson('/api/v1/me/entitlements')->assertOk()->assertJsonPath('data.capabilities.plan', 'artist_pro');
        $this->getJson('/api/v1/billing/checkouts/' . $attempt->reference)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.access_active', true);
        $this->getJson('/api/v1/me/billing/invoices')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount_paid', 1999);
        $this->asUser($this->makeUser('foreign'))->getJson('/api/v1/me/billing/invoices')->assertOk()->assertJsonCount(0, 'data');
    }

    public function testInvoiceArrivingBeforeCheckoutAndOldFailedEventsReconcileCurrentState(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $attempt = $this->checkout('artist_starter');
        $invoice = $this->stripe->complete($attempt->session_id);
        $this->event('invoice.paid', $invoice);
        $this->event('invoice.payment_failed', $invoice);
        $this->event('checkout.session.expired', $attempt->session_id);
        $this->assertSame('artist_starter', Billing::access($owner->id)->plan_code);
        $this->assertSame('completed', query('billing_checkouts')->first()->status);
        $this->assertDatabaseCount('billing_paid_periods', 1);
        $this->assertSame(0, query('billing_paid_periods')->first()->boost_credit_cents);
    }

    public function testCancellationResumeAndFailedRenewalPreserveOnlyVerifiedPaidTime(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $attempt = $this->checkout();
        $invoice = $this->stripe->complete($attempt->session_id);
        $this->event('invoice.paid', $invoice);
        $this->postJson('/api/v1/billing/subscription/cancel')->assertOk()->assertJsonPath('data.cancel_at_period_end', true);
        $this->postJson('/api/v1/billing/subscription/cancel')->assertOk();
        $this->postJson('/api/v1/billing/subscription/resume')->assertOk()->assertJsonPath('data.cancel_at_period_end', false);
        $subscription = query('billing_subscriptions')->first();
        $this->stripe->objects['/subscriptions/' . $subscription->subscription_id]['status'] = 'past_due';
        $renewal = $this->stripe->objects['/invoices/' . $invoice];
        $renewal['id'] = 'in_renewal';
        $renewal['status'] = 'open';
        $renewal['paid'] = false;
        $renewal['amount_paid'] = 0;
        $renewal['lines']['data'][0]['period'] = ['start' => time() + 30 * 86400, 'end' => time() + 60 * 86400];
        $this->stripe->objects['/invoices/in_renewal'] = $renewal;
        $this->event('invoice.payment_failed', 'in_renewal');
        $this->assertDatabaseCount('billing_paid_periods', 1);
        $this->assertSame('artist_pro', Billing::access($owner->id)->plan_code);
        query('billing_paid_periods')->where('id', '>', 0)->update(['ends_at' => Billing::timestamp(time() - 1)]);
        $this->assertSame(null, Billing::access($owner->id));
        $this->stripe->objects['/subscriptions/' . $subscription->subscription_id]['status'] = 'canceled';
        $this->postJson('/api/v1/billing/subscription/resume')->assertStatus(409);
    }

    public function testForeignCustomerAndWrongPricesCannotGrantAccess(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $attempt = $this->checkout();
        $invoice = $this->stripe->complete($attempt->session_id);
        $this->stripe->objects['/invoices/' . $invoice]['customer'] = 'cus_foreign';
        try {
            $this->event('invoice.paid', $invoice);
            $this->fail('Foreign invoice was accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertTrue(str_contains($exception->getMessage(), 'reconciliation failed'));
        }
        $this->assertDatabaseCount('billing_paid_periods', 0);
        $this->assertSame('failed', query('billing_webhooks')->first()->status);
        $this->assertSame(null, Billing::access($owner->id));
    }

    public function testRefundAndDisputeTrackAdjustmentsWithoutRegranting(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $attempt = $this->checkout();
        $invoice = $this->stripe->complete($attempt->session_id);
        $this->event('invoice.paid', $invoice);
        $charge = $this->stripe->objects['/invoices/' . $invoice]['charge'];
        $this->stripe->objects['/disputes/dp_one'] = ['id' => 'dp_one', 'livemode' => false, 'charge' => $charge, 'currency' => 'usd', 'amount' => 1999, 'status' => 'needs_response'];
        $this->event('charge.dispute.created', 'dp_one');
        $this->assertSame(1, query('billing_paid_periods')->first()->promotion_hold);
        $this->assertSame('artist_pro', Billing::access($owner->id)->plan_code);
        $this->stripe->objects['/disputes/dp_one']['status'] = 'won';
        $this->event('charge.dispute.closed', 'dp_one');
        $this->assertSame(0, query('billing_paid_periods')->first()->promotion_hold);
        $this->stripe->objects['/refunds/re_one'] = ['id' => 're_one', 'charge' => $charge, 'currency' => 'usd', 'amount' => 500, 'status' => 'succeeded'];
        $this->stripe->objects['/charges/' . $charge]['amount_refunded'] = 500;
        $this->event('refund.created', 're_one');
        $this->assertSame('review_required', query('billing_adjustments')->where('object_id', 're_one')->first()->allocation);
        $this->assertTrue(Billing::access($owner->id) !== null);
        $this->assertSame(1, query('billing_paid_periods')->first()->promotion_hold);
        Adjustments::allocate(query('billing_adjustments')->where('object_id', 're_one')->first()->id, 'no_service_change', 'Customer retained the complete service period.');
        $this->assertSame(0, query('billing_paid_periods')->first()->promotion_hold);
        $this->event('refund.updated', 're_one');
        $this->assertSame('no_service_change', query('billing_adjustments')->where('object_id', 're_one')->first()->allocation);
        $this->stripe->objects['/refunds/re_two'] = ['id' => 're_two', 'charge' => $charge, 'currency' => 'usd', 'amount' => 1499, 'status' => 'succeeded'];
        $this->stripe->objects['/charges/' . $charge]['amount_refunded'] = 1999;
        $this->event('refund.created', 're_two');
        $this->event('invoice.paid', $invoice);
        $this->assertSame(null, Billing::access($owner->id));
        $this->assertSame(0, query('billing_paid_periods')->first()->boost_credit_cents);
        $this->assertDatabaseCount('billing_paid_periods', 1);
    }
    public function testWrongInvoicePriceAndOutOfBandPaymentsFailButTaxCanBeSettled(): void
    {
        $owner = $this->makeUser();
        $this->makeArtist($owner);
        $this->asUser($owner);
        $attempt = $this->checkout();
        $invoiceId = $this->stripe->complete($attempt->session_id);
        $valid = $this->stripe->objects['/invoices/' . $invoiceId];
        $wrongPrice = $valid;
        $wrongPrice['lines']['data'][0]['price']['id'] = 'price_foreign';
        foreach ([$wrongPrice, [...$valid, 'paid_out_of_band' => true], [...$valid, 'amount_paid' => 0]] as $invalid) {
            $this->stripe->objects['/invoices/' . $invoiceId] = $invalid;
            try {
                $this->event('invoice.paid', $invoiceId);
                $this->fail('Unsettled or unrelated invoice granted access.');
            } catch (\RuntimeException $exception) {
                $this->assertTrue(str_contains($exception->getMessage(), 'reconciliation failed'));
            }
            $this->assertDatabaseCount('billing_paid_periods', 0);
        }
        $this->stripe->objects['/invoices/' . $invoiceId] = [...$valid, 'amount_paid' => 2199, 'amount_due' => 2199];
        $this->stripe->objects['/charges/' . $valid['charge']]['amount'] = 2199;
        $this->stripe->objects['/charges/' . $valid['charge']]['amount_captured'] = 2199;
        $this->event('invoice.paid', $invoiceId);
        $this->assertSame('artist_pro', Billing::access($owner->id)->plan_code);
        $this->assertSame(2199, query('billing_invoices')->first()->amount_paid);
    }

}
