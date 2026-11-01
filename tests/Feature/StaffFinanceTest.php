<?php

namespace Tests\Feature;

use App\Services\Billing\{Adjustments, Billing};
use App\Services\Staff\{Operations, Sessions};
use Spark\Facades\{DB, Hash};
use Tests\Support\{ArtistFixtures, BillingFixtures, StaffBrowser};
use Tests\TestCase;

class StaffFinanceTest extends TestCase
{
    use ArtistFixtures, BillingFixtures, StaffBrowser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->billingSetup();
    }

    private function purchase(): object
    {
        $owner = $this->makeUser('buyer');
        $this->makeArtist($owner);
        $this->asUser($owner);
        $attempt = $this->checkout();
        $invoice = $this->stripe->complete($attempt->session_id);
        $this->event('invoice.paid', $invoice);

        return Billing::table('invoices')->first();
    }

    public function testFinancePagesAreEnvironmentScopedAndNeverExposeProviderPayloads(): void
    {
        $invoice = $this->purchase();
        $adjustment = DB::table('billing_adjustments')->insert([
            'invoice_id' => $invoice->id, 'provider' => 'stripe', 'environment' => 'test', 'object_id' => 're_test',
            'kind' => 'refund', 'status' => 'succeeded', 'amount' => 100, 'currency' => 'usd',
            'created_at' => Billing::timestamp(), 'updated_at' => Billing::timestamp(),
        ]);
        $this->browserLogin($this->makeUser('finance'), 'finance');
        foreach (['subscriptions', 'invoices', 'checkouts', 'adjustments', 'webhooks'] as $module) {
            $this->browserPage('/billing/' . $module)->assertOk()->assertJsonCount(1, 'props.rows');
            $page = $this->browserPage('/billing/' . $module . '/1')->assertOk();
            foreach (['payload', 'idempotency_hash', 'request_hash', 'checkout.stripe.com', 'whsec_test', 'secret_key'] as $secret) {
                $this->assertFalse(str_contains($page->content(), $secret), $secret . ' must not be exposed.');
            }
        }
        $report = $this->browserPage('/billing/reports')->assertOk();
        $report->assertJsonPath('props.report.invoices.0.amount_paid', 1999)->assertJsonPath('props.report.paid_access.0.accounts', 1);
        // A second adjustment must not double count the invoice.
        DB::table('billing_adjustments')->insert([
            'invoice_id' => $invoice->id, 'provider' => 'stripe', 'environment' => 'test', 'object_id' => 'dp_test',
            'kind' => 'dispute', 'status' => 'under_review', 'amount' => 200, 'currency' => 'usd',
            'created_at' => Billing::timestamp(), 'updated_at' => Billing::timestamp(),
        ]);
        $this->browserPage('/billing/reports')->assertOk()->assertJsonPath('props.report.invoices.0.count', 1)->assertJsonPath('props.report.invoices.0.amount_paid', 1999);
        DB::table('billing_adjustments')->where('id', $adjustment)->update(['environment' => 'live']);
        $this->browserPage('/billing/adjustments/' . $adjustment)->assertNotFound();
        $this->browserPage('/billing/invoices/invalid')->assertNotFound();
        $this->browserLogin($this->makeUser('moderator'), 'moderator');
        $this->browserPage('/billing/reports')->assertForbidden();
        $this->browserPage('/billing/invoices')->assertForbidden();
        $this->browserPage('/')->assertOk()->assertJsonPath('props.finance', null);
    }

    public function testAdjustmentAllocationLocksVersionRequiresFreshConfirmationAndAuditsStaffActor(): void
    {
        $invoice = $this->purchase();
        $id = (int) DB::table('billing_adjustments')->insert([
            'invoice_id' => $invoice->id, 'provider' => 'stripe', 'environment' => 'test', 'object_id' => 're_review',
            'kind' => 'refund', 'status' => 'succeeded', 'amount' => 100, 'currency' => 'usd',
            'created_at' => Billing::timestamp(), 'updated_at' => Billing::timestamp(),
        ]);
        $this->browserLogin($actor = $this->makeUser('finance'), 'finance');
        $version = $this->browserPage('/billing/adjustments/' . $id)->assertOk()->json('props.allocation.version');
        $input = ['allocation' => 'no_service_change', 'reason' => 'Reviewed partial goodwill refund', 'version' => $version];
        $this->post('/billing/adjustments/' . $id . '/allocation', $input, $this->browserHeaders())->assertStatus(419);
        DB::table('staff_sessions')->where('user_id', $actor->id)->update(['mfa_at' => Sessions::timestamp(-400)]);
        $this->assertBrowserRejected($this->browserWrite('/billing/adjustments/' . $id . '/allocation', $input));
        DB::table('staff_sessions')->where('user_id', $actor->id)->update(['mfa_at' => Sessions::timestamp()]);
        $this->browserWrite('/billing/adjustments/' . $id . '/allocation', $input)->assertStatus(303);
        $this->assertDatabaseHas('billing_adjustments', ['id' => $id, 'allocation' => 'no_service_change']);
        $this->assertDatabaseHas('audit_events', ['action' => 'billing.adjustment.allocated', 'actor_id' => $actor->id, 'actor_type' => 'staff']);
        $this->assertBrowserRejected($this->browserWrite('/billing/adjustments/' . $id . '/allocation', [...$input, 'allocation' => 'full_period']));
        $this->assertDatabaseHas('billing_paid_periods', ['invoice_id' => $invoice->id, 'revoked_at' => null]);
        // CLI compatibility retains its explicit console actor.
        Adjustments::allocate($id, 'no_service_change', 'Console follow-up review');
        $this->assertDatabaseHas('audit_events', ['action' => 'billing.adjustment.allocated', 'actor_type' => 'console']);
        $context = new \App\Services\Staff\Context($actor,
            DB::table('staff_accounts')->where('user_id', $actor->id)->first(),
            DB::table('staff_sessions')->where('user_id', $actor->id)->whereNull('revoked_at')->first());
        DB::table('staff_accounts')->where('user_id', $actor->id)->update(['role' => 'support', 'version' => 2]);
        $this->assertThrows(\App\Services\Staff\StaffException::class,
            fn () => Adjustments::allocate($id, 'full_period', 'Stale authorization must not apply', $context));
        $this->assertDatabaseHas('billing_adjustments', ['id' => $id, 'allocation' => 'no_service_change']);
    }

    public function testQueuedBillingRecoveryDeduplicatesAndRechecksRoleBeforeProviderCalls(): void
    {
        $this->purchase();
        $this->browserLogin($actor = $this->makeUser('finance'), 'finance');
        $input = ['reason' => 'Provider connection restored', 'idempotency_key' => 'subscription-recovery-one'];
        $this->browserWrite('/billing/subscriptions/1/reconcile', $input)->assertStatus(303);
        $this->browserWrite('/billing/subscriptions/1/reconcile', $input)->assertStatus(303);
        $this->assertDatabaseCount('staff_operations', 1);
        $id = (int) DB::table('staff_operations')->value('id');
        $this->browserPage('/operations/actions/' . $id)->assertOk();
        Operations::run($id);
        $this->assertDatabaseHas('staff_operations', ['id' => $id, 'status' => 'completed', 'result_code' => 'subscription_reconciled']);
        $this->browserWrite('/billing/subscriptions/1/reconcile', [...$input, 'idempotency_key' => 'subscription-recovery-two'])->assertStatus(303);
        $second = (int) DB::table('staff_operations')->orderBy('id', 'desc')->value('id');
        DB::table('staff_accounts')->where('user_id', $actor->id)->update(['role' => 'support', 'version' => 2]);
        Operations::run($second);
        $this->assertDatabaseHas('staff_operations', ['id' => $second, 'status' => 'cancelled']);
    }

    public function testFailedReceiptRetryRecordsARealDurableOutcome(): void
    {
        $id = (int) DB::table('billing_webhooks')->insert([
            'provider' => 'stripe', 'environment' => 'test', 'event_id' => 'evt_safe_retry', 'type' => 'unknown.event', 'status' => 'failed',
            'payload' => Hash::encrypt(json_encode(['type' => 'unknown.event', 'data' => ['object' => ['id' => 'object_safe']]])),
            'created_at' => Billing::timestamp(), 'updated_at' => Billing::timestamp(),
        ]);
        $this->browserLogin($this->makeUser('finance'), 'finance');
        $this->browserWrite('/billing/webhooks/' . $id . '/retry', ['reason' => 'Receipt reviewed for replay', 'idempotency_key' => 'receipt-recovery-one'])->assertStatus(303);
        (new \App\Jobs\StaffMaintenance())->handle();
        $this->assertDatabaseHas('billing_webhooks', ['id' => $id, 'status' => 'ignored', 'attempts' => 1]);
        $this->assertDatabaseHas('staff_operations', ['status' => 'completed', 'result_code' => 'receipt_processed']);
    }

    public function testFinanceRangesKeepCurrenciesSeparateAndExportsFreezeRequestedDates(): void
    {
        $invoice = $this->purchase();
        $day = (new \DateTimeImmutable('yesterday', new \DateTimeZone('UTC')))->format('Y-m-d');
        $until = (new \DateTimeImmutable($day, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
        DB::table('billing_invoices')->where('id', $invoice->id)->update(['issued_at' => $day . ' 00:00:00']);
        $copy = (array) $invoice;
        unset($copy['id']);
        DB::table('billing_invoices')->insert([...$copy, 'invoice_id' => 'in_euro_boundary', 'currency' => 'eur', 'amount_paid' => 1500, 'issued_at' => $day . ' 23:59:59']);
        DB::table('billing_invoices')->insert([...$copy, 'invoice_id' => 'in_exclusive_boundary', 'issued_at' => $until . ' 00:00:00']);
        DB::table('billing_invoices')->insert([...$copy, 'invoice_id' => 'in_other_environment', 'environment' => 'live', 'issued_at' => $day . ' 12:00:00']);
        $this->browserLogin($this->makeUser('finance'), 'finance');
        $this->browserPage('/billing/reports?period=custom&from=' . $day . '&to=' . $day)->assertOk()
            ->assertJsonCount(2, 'props.report.invoices')->assertJsonPath('props.report.invoices.0.currency', 'EUR')
            ->assertJsonPath('props.report.invoices.0.amount_paid', 1500)->assertJsonPath('props.report.invoices.1.amount_paid', 1999)
            ->assertJsonPath('props.report.invoices.1.count', 1);
        $this->browserWrite('/exports', ['type' => 'finance', 'period' => 'month', 'idempotency_key' => 'frozen-finance-dates'])->assertStatus(303);
        // Represents a preset resolved before midnight, still waiting for its worker.
        $filters = json_encode(['period' => 'month', 'from' => $day, 'to' => $day, 'timezone' => 'UTC']);
        DB::table('staff_exports')->where('id', 1)->update(['filters' => $filters]);
        $this->browserWrite('/exports', ['type' => 'finance', 'period' => 'month', 'idempotency_key' => 'frozen-finance-dates'])->assertStatus(303);
        $this->assertSame([], session()->getFlash('errors', []));
        $this->assertDatabaseCount('staff_exports', 1);
        $this->assertDatabaseCount('staff_operations', 1);
        $this->assertDatabaseHas('staff_exports', ['id' => 1, 'filters' => $filters, 'status' => 'queued']);
        Operations::run((int) DB::table('staff_operations')->value('id'));
        $csv = $this->get('/exports/1/download')->assertOk()->content();
        $this->assertTrue(str_contains($csv, '1999,USD,' . $day . 'T00:00:00Z,' . $until . 'T00:00:00Z'), $csv);
        $this->assertTrue(str_contains($csv, '1500,EUR,' . $day . 'T00:00:00Z'));
    }

    public function testDeletionCancellationSurvivesAnInFlightRecoveryFinishing(): void
    {
        $id = (int) DB::table('billing_webhooks')->insert([
            'provider' => 'stripe', 'environment' => 'test', 'event_id' => 'evt_cancelled_retry', 'type' => 'unknown.event',
            'status' => 'failed', 'payload' => Hash::encrypt('{}'), 'created_at' => Billing::timestamp(), 'updated_at' => Billing::timestamp(),
        ]);
        $this->browserLogin($actor = $this->makeUser('finance'), 'finance');
        $this->browserWrite('/billing/webhooks/' . $id . '/retry', [
            'reason' => 'Review cancellation during recovery', 'idempotency_key' => 'receipt-deletion-during-recovery',
        ])->assertStatus(303);
        $this->app->instance(\App\Services\Billing\Webhooks::class, new class($actor->id) extends \App\Services\Billing\Webhooks {
            public function __construct(private int $actorId)
            {
            }

            public function process(int $id, ?int $maxPages = null): void
            {
                \App\Services\Staff\Exports::deleting($this->actorId);
                DB::table('billing_webhooks')->where('id', $id)->update(['status' => 'ignored']);
            }
        });
        $operation = (int) DB::table('staff_operations')->value('id');
        Operations::run($operation);
        Operations::run($operation);
        $this->assertDatabaseHas('staff_operations', ['id' => $operation, 'status' => 'cancelled', 'result_code' => 'account_deleted']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'staff.operation.finished']);
    }
}
