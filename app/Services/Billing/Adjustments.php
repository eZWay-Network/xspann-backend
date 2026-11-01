<?php

namespace App\Services\Billing;

use App\Services\Staff\{Context, Sessions};
use Spark\Facades\DB;
use function in_array;
use function is_int;
use function is_string;

class Adjustments
{
    public function __construct(private PaymentProvider $provider)
    {
    }

    /** Refunds have no livemode. Their verified, owned charge establishes the environment. */
    public function settlement(array $invoice, string $customerId): array
    {
        abort_unless(
            is_string($invoice['charge'] ?? null),
            409,
            'Invoice has no settled charge.'
        );

        $charge = $this->provider->request('GET', '/charges/' . rawurlencode($invoice['charge']));
        Billing::assertEnvironment($charge);

        abort_unless(
            ($charge['id'] ?? null) === $invoice['charge']
            && ($charge['invoice'] ?? null) === $invoice['id']
            && ($charge['customer'] ?? null) === $customerId
            && ($charge['currency'] ?? null) === $invoice['currency']
            && ($charge['paid'] ?? null) === true && ($charge['captured'] ?? null) === true
            && ($charge['amount'] ?? null) === $invoice['amount_paid']
            && ($charge['amount_captured'] ?? null) === $invoice['amount_paid'],
            409,
            'Charge does not settle the owned invoice.'
        );

        return $charge;
    }

    public function apply(object $invoice, string $kind, array $object, array $charge): void
    {
        Billing::assertEnvironment($charge);
        if ($kind === 'dispute') {
            Billing::assertEnvironment($object);
        }

        $statuses = $kind === 'refund' ? ['pending', 'requires_action', 'succeeded', 'failed', 'canceled']
            : ['warning_needs_response', 'warning_under_review', 'warning_closed', 'needs_response', 'under_review', 'won', 'lost'];

        abort_unless(
            in_array($kind, ['refund', 'dispute'], true)
            && is_string($object['id'] ?? null) && $object['id'] !== ''
            && in_array($object['status'] ?? null, $statuses, true)
            && ($charge['id'] ?? null) === $invoice->charge_id
            && ($charge['invoice'] ?? null) === $invoice->invoice_id
            && ($object['charge'] ?? null) === $invoice->charge_id
            && ($object['currency'] ?? null) === $invoice->currency
            && is_int($object['amount'] ?? null) && $object['amount'] > 0
            && $object['amount'] <= $invoice->amount_paid,
            409,
            'Adjustment does not match the paid invoice.'
        );

        DB::transaction(function () use ($invoice, $kind, $object): void {
            $subscription = DB::table('billing_subscriptions')->where('id', $invoice->subscription_id)->first();
            if ($subscription->user_id) {
                \App\Models\User::increment('id', 0, where: ['id' => $subscription->user_id]);
            }

            $existing = Billing::table('adjustments')->where('object_id', $object['id'])->first();
            abort_if(
                $existing && $existing->invoice_id !== $invoice->id,
                409,
                'Adjustment ownership conflict.'
            );

            $allocation = $existing?->allocation ?? 'review_required';
            if ($kind === 'refund' && $object['status'] === 'succeeded' && $object['amount'] === $invoice->amount_paid) {
                $allocation = 'full_period';
            }
            if ($kind === 'refund' && in_array($object['status'], ['failed', 'canceled'], true)) {
                $allocation = 'no_service_change';
            }
            if ($kind === 'dispute' && in_array($object['status'], ['won', 'warning_closed'], true)) {
                $allocation = 'released';
            }

            DB::table('billing_adjustments')->upsert([
                'invoice_id' => $invoice->id,
                'provider' => 'stripe',
                'environment' => config('billing.environment'),
                'object_id' => $object['id'],
                'kind' => $kind,
                'status' => $object['status'],
                'amount' => $object['amount'],
                'currency' => $object['currency'],
                'allocation' => $allocation,
                'created_at' => Billing::timestamp(),
                'updated_at' => Billing::timestamp(),
            ], ['provider', 'environment', 'object_id'], ['status', 'amount', 'allocation', 'updated_at']);

            self::refreshPeriod($invoice);
        });
    }

    public static function refreshPeriod(object $invoice): void
    {
        $refunds = Billing::table('adjustments')->where('invoice_id', $invoice->id)
            ->where('kind', 'refund')->where('status', 'succeeded')->get();

        $refunded = 0;
        $revoke = false;
        foreach ($refunds as $refund) {
            $refunded += $refund->amount;
            $revoke = $revoke || $refund->allocation === 'full_period';
        }

        $revoke = $revoke || ($invoice->amount_paid > 0 && $refunded >= $invoice->amount_paid);
        $hold = Billing::table('adjustments')->where('invoice_id', $invoice->id)
            ->where('kind', 'dispute')->where('allocation', '!=', 'released')->exists();

        $hold = $hold || Billing::table('adjustments')->where('invoice_id', $invoice->id)
            ->where('kind', 'refund')->where('status', 'succeeded')->where('allocation', 'review_required')->exists();

        $updates = ['promotion_hold' => $hold, 'updated_at' => Billing::timestamp()];
        if ($revoke) {
            $updates['revoked_at'] = Billing::timestamp();
            $updates['boost_credit_cents'] = 0;
        }

        $period = DB::table('billing_paid_periods')->where('invoice_id', $invoice->id)->first();
        DB::table('billing_paid_periods')->where('invoice_id', $invoice->id)->update($updates);
        if ($period && $revoke && !$period->revoked_at) {
            \App\Services\Promotions\Invalidations::record('period', $period->id);
        }
        $owner = DB::table('billing_subscriptions')->where('id', $invoice->subscription_id)->value('user_id');
        if ($owner) {
            if ($hold && $period && !$period->promotion_hold) {
                \App\Services\Promotions\Invalidations::record('user', (int) $owner);
            }
            \App\Services\Realtime::record((int) $owner, 'entitlements.updated', (int) $owner, 0);
        }
    }

    public static function version(object $record): string
    {
        return hash('sha256', json_encode([
            $record->id, $record->invoice_id, $record->environment, $record->kind, $record->status,
            $record->amount, $record->currency, $record->allocation, $record->reviewed_at, $record->review_note,
        ], JSON_THROW_ON_ERROR));
    }

    public static function allocate(int $id, string $allocation, string $reason, Context|int|null $actor = null, ?string $version = null): void
    {
        abort_unless(
            in_array($allocation, ['full_period', 'no_service_change', 'released'], true) && mb_strlen(trim($reason)) >= 10,
            422,
            'Supply an explicit allocation and review reason.'
        );

        $record = Billing::table('adjustments')->where('id', $id)->first();
        abort_unless(
            (bool) $record,
            404,
            'Adjustment not found.'
        );

        $invoice = DB::table('billing_invoices')->where('id', $record->invoice_id)->first();
        $subscription = DB::table('billing_subscriptions')->where('id', $invoice->subscription_id)->first();
        Billing::locked($subscription->user_id ?? -$subscription->customer_id, fn() => DB::transaction(function () use ($id, $allocation, $reason, $invoice, $subscription, $actor, $version): void {
            // Take the billing mutex before locking browser identity rows, as provider recovery does.
            if ($actor instanceof Context) {
                $current = Sessions::requireCurrent($actor);
                $current->authorize('billing.adjustments.allocate');
                abort_unless($current->recent((int) config('staff.confirmation_seconds')), 409, 'Confirm your credentials and MFA before allocating this adjustment.');
            }
            $actorId = $actor instanceof Context ? (int) $actor->user->id : $actor;
            if ($subscription->user_id) {
                \App\Models\User::increment('id', 0, where: ['id' => $subscription->user_id]);
            }

            $record = Billing::table('adjustments')->where('id', $id)->lockForUpdate()->first();
            abort_unless($record !== null, 404);
            abort_unless($version === null || hash_equals(self::version($record), $version), 409, 'The adjustment changed. Reload and review it again.');
            abort_unless(($record->kind === 'refund' && $record->status === 'succeeded' && $allocation !== 'released')
                || ($record->kind === 'dispute' && $allocation === 'released'), 422, 'Allocation does not apply to this adjustment.');

            DB::table('billing_adjustments')->where('id', $id)->update([
                'allocation' => $allocation,
                'review_note' => $reason,
                'reviewed_at' => Billing::timestamp(),
                'updated_at' => Billing::timestamp(),
            ]);

            self::refreshPeriod($invoice);

            \App\Services\AuditTrail::record('billing.adjustment.allocated', ['adjustment_id' => $id, 'allocation' => $allocation, 'previous_allocation' => $record->allocation, 'reason' => trim($reason)], $actorId, $actorId === null ? 'console' : 'staff');
        }));
    }
}
