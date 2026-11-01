<?php

namespace App\Services\Staff;

use App\Models\{Audio, User, Video};
use App\Services\{Announcements, AudioRetry, Realtime, Releases, VideoRetry};
use App\Services\Billing\{Billing, PaymentProvider, Reconciliation, SubscriptionActions, Webhooks};
use Spark\Facades\DB;

/** Only these domain targets can be recovered. No commands or serialized jobs enter this boundary. */
final class Recovery
{
    public static function assertEligible(string $type, int $id, Context $context): void
    {
        if ($type === 'export.generate') {
            Exports::owned($context, $id);

            return;
        }
        $row = match ($type) {
            'privacy.retry' => DB::table('privacy_requests')->where('id', $id)->first(),
            'billing.webhook' => Billing::table('webhooks')->where('id', $id)->first(),
            'billing.reconcile' => Billing::table('subscriptions')->where('id', $id)->first(),
            'billing.checkout' => Billing::table('checkouts')->where('id', $id)->first(),
            'media.video', 'release.recover' => DB::table('videos')->where('id', $id)->first(),
            'media.audio' => DB::table('audios')->where('id', $id)->first(),
            'communications.retry' => DB::table('announcements')->where('id', $id)->first(),
            'realtime.recover' => (object) ['id' => 0],
            default => null,
        };
        abort_unless($row !== null, 404);
        abort_unless(self::eligible($type, $row), 409, 'This record is not currently eligible for recovery.');
    }

    public static function eligible(string $type, object $row): bool
    {
        return match ($type) {
            'privacy.retry' => $row->status === 'failed',
            'billing.webhook' => in_array($row->status, ['failed', 'pending'], true) && $row->payload !== null,
            'billing.reconcile' => true,
            'billing.checkout' => $row->status === 'pending',
            'media.video' => $row->status !== 'deleted' && $row->moderation_status === 'approved'
                && ($row->processing_state === 'failed' || ($row->processing_state === 'processing' && (new \DateTimeImmutable($row->updated_at, new \DateTimeZone(config('app.timezone', 'UTC'))))->getTimestamp() < time() - 3600)),
            'media.audio' => $row->origin === 'upload' && $row->moderation_status === 'approved' && $row->rights_confirmed_at !== null
                && ($row->status === 'failed' || ($row->status === 'processing' && (new \DateTimeImmutable($row->updated_at, new \DateTimeZone(config('app.timezone', 'UTC'))))->getTimestamp() < time() - 3600)),
            'release.recover' => $row->status !== 'deleted' && $row->publication_state === 'scheduled'
                && $row->publish_at !== null && $row->publish_at <= Sessions::timestamp(),
            'communications.retry' => $row->status === 'queued',
            'realtime.recover' => (int) $row->id === 0,
            default => false,
        };
    }

    public static function run(object $operation, object $member): array
    {
        $id = (int) $operation->target_id;
        $type = $operation->type;
        if ($type === 'export.generate') {
            return Exports::generate($id, (int) $operation->actor_id, $member);
        }
        if ($type === 'billing.webhook') {
            $row = Billing::table('webhooks')->where('id', $id)->first();
            if (!$row || in_array($row->status, ['processed', 'ignored'], true)) {
                return ['no_op', 'receipt_already_processed'];
            }
            if (!self::eligible($type, $row) && !($operation->status === 'running' && $row->status === 'processing' && $row->payload)) {
                return ['no_op', 'receipt_not_retryable'];
            }
            app(Webhooks::class)->process($id, maxPages: 10);

            return ['completed', 'receipt_processed'];
        }
        if (in_array($type, ['billing.reconcile', 'billing.checkout'], true)) {
            $record = Billing::table($type === 'billing.reconcile' ? 'subscriptions' : 'checkouts')->where('id', $id)->first();
            $attempt = $type === 'billing.reconcile' && $record ? Billing::table('checkouts')->where('id', $record->checkout_id)->first() : $record;
            if (!$attempt || ($type === 'billing.checkout' && !self::eligible($type, $attempt))) {
                return ['no_op', 'checkout_not_pending'];
            }

            return Billing::locked($attempt->user_id ?? -$attempt->customer_id, function () use ($attempt): array {
                Billing::assertAccount(app(PaymentProvider::class));
                $service = app(Reconciliation::class);
                $subscription = $service->recoverCheckout($attempt, validateOffer: false, maxPages: 10);
                if ($subscription) {
                    $owner = $subscription->user_id ? \App\Models\User::find($subscription->user_id) : null;
                    if ($subscription->deletion_requested_at || !$owner || $owner->status === 'deleting') {
                        app(SubscriptionActions::class)->stopForDeletion($subscription);
                    }
                    $service->invoices($subscription, maxPages: 10);

                    return ['completed', 'subscription_reconciled'];
                }
                $status = Billing::table('checkouts')->where('id', $attempt->id)->value('status');

                return $status === 'expired' ? ['completed', 'checkout_expired'] : ['no_op', 'purchase_not_settled'];
            });
        }
        if (in_array($type, ['media.video', 'media.audio'], true)) {
            $video = $type === 'media.video';
            $row = $video ? Video::find($id) : Audio::find($id);
            if (!$row) {
                return ['no_op', 'media_unavailable'];
            }
            $available = $video ? Releases::eligible($row)
                : ($row->origin === 'upload' && $row->status !== 'deleted' && $row->moderation_status === 'approved'
                    && $row->rights_confirmed_at !== null && User::visibleTo(null)->whereKey($row->user_id)->exists());
            if (!$available) {
                return ['no_op', 'media_not_retryable'];
            }
            $state = $video ? $row->processing_state : $row->status;
            // A worker can stop after dispatch but before recording awaiting_media.
            if ($operation->result_code === 'awaiting_media'
                || ($operation->status === 'running' && in_array($state, ['processing', 'ready', 'published'], true))) {

                return $state === 'processing' ? ['running', 'awaiting_media']
                    : (in_array($state, ['ready', 'published'], true) ? ['completed', 'media_ready'] : ['failed', 'media_processing_failed']);
            }
            if (!self::eligible($type, $row)) {
                return ['no_op', 'media_not_retryable'];
            }
            $queued = $video ? VideoRetry::retry($id) : AudioRetry::retry($id);

            return $queued ? ['running', 'awaiting_media'] : ['no_op', 'media_not_retryable'];
        }
        if ($type === 'release.recover') {
            $row = DB::table('videos')->where('id', $id)->first();
            if (!$row || !self::eligible($type, $row)) {
                return ['no_op', 'release_not_due'];
            }
            app(Releases::class)->runDue($id);
            $state = DB::table('videos')->where('id', $id)->value('publication_state');

            return $state === 'published' ? ['completed', 'release_published']
                : ($state === 'draft' ? ['no_op', 'release_requires_owner_review'] : ['failed', 'release_recovery_failed']);
        }
        if ($type === 'communications.retry') {
            $row = DB::table('announcements')->where('id', $id)->first();
            if (!$row || !self::eligible($type, $row)) {
                return ['no_op', 'announcement_not_pending'];
            }
            Announcements::deliver($id);
            $pending = DB::table('announcement_deliveries')->where('announcement_id', $id)->where('status', 'pending')->exists();

            return $pending ? ['failed', 'delivery_remains_pending'] : ['completed', 'delivery_completed_or_suppressed'];
        }
        if ($type === 'realtime.recover') {
            $ids = DB::table('realtime_outbox')->whereNull('completed_at')->where('available_at', '<=', Billing::timestamp())
                ->orderBy('id')->limit(100)->pluck('id');
            if (!$ids) {
                return ['no_op', 'outbox_empty'];
            }
            foreach ($ids as $event) {
                Realtime::publish((int) $event);
            }
            $pending = DB::table('realtime_outbox')->whereIn('id', $ids)->whereNull('completed_at')->exists();

            return $pending ? ['failed', 'outbox_remains_pending'] : ['completed', 'outbox_batch_completed'];
        }

        return ['cancelled', 'unknown_operation'];
    }
}
