<?php

namespace App\Queries\Staff;

use App\Http\Requests\Staff\ListFilters;
use App\Http\Resources\Staff\Records;
use App\Services\Billing\{Adjustments, Billing};
use App\Services\Staff\{Context, Recovery};
use Spark\Facades\DB;
use Spark\Http\Request;

final class BillingRecords
{
    private const COLUMNS = [
        'subscriptions' => ['id', 'user_id', 'checkout_id', 'catalog_id', 'status', 'cancel_at_period_end', 'period_end', 'reconciled_at', 'deletion_requested_at', 'created_at', 'updated_at'],
        'invoices' => ['id', 'subscription_id', 'status', 'amount_paid', 'amount_due', 'currency', 'issued_at', 'created_at', 'updated_at'],
        'checkouts' => ['id', 'user_id', 'catalog_id', 'reference', 'status', 'request_started_at', 'expires_at', 'created_at', 'updated_at'],
        'adjustments' => ['id', 'invoice_id', 'kind', 'status', 'amount', 'currency', 'allocation', 'review_note', 'reviewed_at', 'created_at', 'updated_at'],
        'webhooks' => ['id', 'type', 'status', 'attempts', 'error_code', 'processed_at', 'created_at', 'updated_at'],
    ];
    private const STATUSES = [
        'subscriptions' => ['incomplete', 'incomplete_expired', 'trialing', 'active', 'past_due', 'canceled', 'unpaid', 'paused'],
        'invoices' => ['draft', 'open', 'paid', 'uncollectible', 'void'],
        'checkouts' => ['pending', 'completed', 'expired'],
        'adjustments' => ['pending', 'requires_action', 'succeeded', 'failed', 'canceled', 'warning_needs_response', 'warning_under_review', 'warning_closed', 'needs_response', 'under_review', 'won', 'lost'],
        'webhooks' => ['pending', 'queued', 'processing', 'processed', 'ignored', 'failed'],
    ];
    private const ACTIONS = [
        'subscriptions' => ['billing.reconcile', 'Reconcile subscription', 'reconcile'],
        'checkouts' => ['billing.checkout', 'Recover checkout', 'recover'],
        'webhooks' => ['billing.webhook', 'Retry receipt', 'retry'],
    ];

    public static function listing(Request $request, string $module): array
    {
        Context::from($request)->authorize('billing.view');
        $choices = ['status' => self::STATUSES[$module]];
        if (in_array($module, ['invoices', 'adjustments'], true)) {
            $choices['currency'] = Billing::table($module)->distinct()->orderBy('currency')->pluck('currency');
        }
        if ($module === 'adjustments') {
            $choices['kind'] = ['refund', 'dispute'];
            $choices['allocation'] = ['review_required', 'full_period', 'no_service_change', 'released'];
        }
        if ($module === 'adjustments') {
            $choices['invoice_id'] = [];
        } elseif ($module === 'invoices') {
            $choices['subscription_id'] = [];
        }
        $filters = ListFilters::read($request, $choices);
        $query = Billing::table($module)->select(self::COLUMNS[$module]);
        foreach (array_keys($choices) as $key) {
            if ($filters[$key] !== '') {
                $query->where($key, $filters[$key]);
            }
        }
        if ($filters['q'] !== '') {
            $query->where('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0);
        }

        return Records::listing($query, $filters, fn ($row) => Records::row($row, ucfirst(rtrim($module, 's')) . ' #' . $row->id,
            (string) route('staff.billing.' . $module . '.show', ['record' => $row->id]), $row->status,
            isset($row->currency) ? strtoupper($row->currency) . ' · ' . ($row->amount_paid ?? $row->amount) . ' minor units' : ($row->type ?? null), 'UTC'),
            'staff.billing.' . $module . '.index', $choices) + [
                'page' => ['title' => ucfirst($module), 'breadcrumbs' => []],
                'description' => ucfirst(config('billing.environment')) . ' billing records. Search by local record ID.',
            ];
    }

    public static function detail(Request $request, string $module, int $id): array
    {
        $context = Context::from($request);
        $context->authorize('billing.view');
        $row = Billing::table($module)->where('id', $id)->firstOrFail();
        $fields = Records::fields($row, self::COLUMNS[$module], 'UTC');
        foreach ($fields as &$field) {
            if (in_array($field['label'], ['Amount', 'Amount paid', 'Amount due'], true)) {
                $field['label'] .= ' (minor units)';
            }
        }
        unset($field);
        $fields[] = ['label' => 'Environment', 'value' => config('billing.environment')];
        $links = [];
        foreach (['checkout_id' => 'checkouts', 'subscription_id' => 'subscriptions', 'invoice_id' => 'invoices'] as $key => $related) {
            if (isset($row->$key) && Billing::table($related)->where('id', $row->$key)->exists()) {
                $links[] = ['label' => ucfirst(rtrim($related, 's')) . ' #' . $row->$key,
                    'url' => (string) route('staff.billing.' . $related . '.show', ['record' => $row->$key])];
            }
        }
        if (isset($row->catalog_id)) {
            $catalog = Billing::table('catalog')->where('id', $row->catalog_id)->first();
            $fields[] = ['label' => 'Plan', 'value' => $catalog?->plan_code];
        }
        if ($module === 'subscriptions') {
            foreach ($fields as &$field) {
                if ($field['label'] === 'Period end') {
                    $field['value'] = Billing::date($row->period_end);
                }
            }
            unset($field);
            $links[] = ['label' => 'Subscription invoices', 'url' => (string) route('staff.billing.invoices.index')->withQuery(['subscription_id' => $id])];
            $access = $row->user_id ? Billing::access((int) $row->user_id) : null;
            $fields[] = ['label' => 'Effective account plan', 'value' => $access?->plan_code ?? 'free'];
            $fields[] = ['label' => 'Account paid through', 'value' => Billing::date($access?->ends_at)];
            $fields[] = ['label' => 'Provider subscription', 'value' => $row->subscription_id];
        }
        if ($module === 'invoices') {
            $links[] = ['label' => 'All invoice adjustments', 'url' => (string) route('staff.billing.adjustments.index')->withQuery(['invoice_id' => $id])];
            $period = DB::table('billing_paid_periods')->where('invoice_id', $id)->first();
            if ($period) {
                $fields = [...$fields, ...Records::fields($period, ['starts_at', 'ends_at', 'revoked_at', 'promotion_hold'], 'UTC')];
            }
            foreach (Billing::table('adjustments')->select(['id', 'kind'])->where('invoice_id', $id)->orderBy('id')->limit(50)->get() as $adjustment) {
                $links[] = ['label' => ucfirst($adjustment->kind) . ' #' . $adjustment->id,
                    'url' => (string) route('staff.billing.adjustments.show', ['record' => $adjustment->id])];
            }
        }
        $action = null;
        if (isset(self::ACTIONS[$module])) {
            [$type, $label, $method] = self::ACTIONS[$module];
            if ($context->can(\App\Services\Staff\Operations::TYPES[$type][0]) && Recovery::eligible($type, $row)) {
                $action = ['label' => $label, 'url' => (string) route('staff.billing.' . $module . '.' . $method, ['record' => $id])];
            }
        }
        $allocation = null;
        if ($module === 'adjustments' && $context->can('billing.adjustments.allocate')) {
            $choices = $row->kind === 'refund' && $row->status === 'succeeded' ? ['full_period', 'no_service_change']
                : ($row->kind === 'dispute' ? ['released'] : []);
            if ($choices) {
                $allocation = [
                    'url' => (string) route('staff.billing.adjustments.allocate', ['record' => $id]),
                    'version' => Adjustments::version($row), 'choices' => $choices,
                    'confirmation_url' => (string) route('staff.challenge')->withQuery(['destination' => '/billing/adjustments/' . $id]),
                    'confirmed' => $context->recent((int) config('staff.confirmation_seconds')),
                ];
            }
        }

        return [
            'page' => ['title' => ucfirst(rtrim($module, 's')) . ' #' . $id, 'breadcrumbs' => []],
            'fields' => $fields, 'links' => $links, 'recovery' => $action, 'allocation' => $allocation,
            'idempotency_key' => bin2hex(random_bytes(16)),
        ];
    }
}
