<?php

namespace App\Services\Staff;

use App\Jobs\ProcessStaffOperation;
use App\Models\User;
use App\Services\{AuditTrail, PrivacyRequests};
use Spark\Facades\{Cache, DB};

final class Operations
{
    public const TYPES = [
        'privacy.retry' => ['privacy.retry', 'privacy.view', '/operations/privacy/'],
        'billing.webhook' => ['billing.webhooks.retry', 'billing.view', '/billing/webhooks/'],
        'billing.reconcile' => ['billing.reconcile', 'billing.view', '/billing/subscriptions/'],
        'billing.checkout' => ['billing.reconcile', 'billing.view', '/billing/checkouts/'],
        'media.video' => ['media.retry', 'operations.view', '/operations/media'],
        'media.audio' => ['media.retry', 'operations.view', '/operations/media'],
        'release.recover' => ['releases.recover', 'operations.view', '/releases/'],
        'communications.retry' => ['communications.retry', 'communications.view', '/operations/communications'],
        'realtime.recover' => ['communications.retry', 'communications.view', '/operations/communications'],
        'export.generate' => ['exports.create', 'exports.create', '/exports/'],
    ];

    public static function privacyRetry(Context $context, int $id, array $input): int
    {
        return self::enqueue($context, 'privacy.retry', $id, $input);
    }

    public static function enqueue(Context $context, string $type, int $id, array $input, bool $dispatch = true): int
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $context->authorize(self::TYPES[$type][0]);
        $key = hash('sha256', $input['idempotency_key']);
        $environment = config('billing.environment');
        $persist = function () use ($context, $type, $id, $input, $key, $environment): int {
            $current = Sessions::requireCurrent($context);
            $current->authorize(self::TYPES[$type][0]);
            $fingerprint = hash('sha256', json_encode([$id, trim($input['reason']), $environment], JSON_THROW_ON_ERROR));
            $old = DB::table('staff_operations')->where('actor_id', $current->user->id)->where('type', $type)->where('idempotency_hash', $key)->first();

            if ($old) {
                abort_unless(hash_equals($old->request_hash, $fingerprint), 409, 'This retry reference has different input.');

                return (int) $old->id;
            }

            Recovery::assertEligible($type, $id, $current);
            $pending = DB::table('staff_operations')->where('type', $type)->where('target_id', $id)
                ->where('environment', $environment)->whereIn('status', ['queued', 'running'])->exists();
            abort_if($pending, 409, 'Recovery is already queued for this record.');
            $operation = DB::table('staff_operations')->insert([
                'actor_id' => $current->user->id,
                'membership_version' => $current->membership->version,
                'type' => $type,
                'target_id' => $id,
                'environment' => $environment,
                'idempotency_hash' => $key,
                'request_hash' => $fingerprint,
                'status' => 'queued',
                'expires_at' => Sessions::timestamp(86400),
                'created_at' => Sessions::timestamp(),
                'updated_at' => Sessions::timestamp(),
            ]);
            AuditTrail::record('staff.operation.queued', [
                'operation_id' => $operation, 'type' => $type, 'target_id' => $id, 'reason' => trim($input['reason']),
            ], $current->user->id, 'staff');

            return (int) $operation;
        };
        $operation = Cache::lock('staff.retry.' . $context->user->id . '.' . $key, 30)->block(5,
            fn () => Cache::lock("staff.target.$environment.$type.$id", 30)->block(5, fn () => DB::transaction($persist)));

        if ($dispatch) {
            self::dispatch($operation);
        }

        return $operation;
    }

    public static function dispatch(int $operation): void
    {
        try {
            ProcessStaffOperation::dispatch($operation)->onQueue('staff')->send();
        } catch (\Throwable) {
            // StaffMaintenance recovers the committed operation if dispatch is interrupted.
        }
    }

    public static function run(int $id): void
    {
        Cache::lock("staff.operation.$id", 3600)->get(function () use ($id): void {
            $row = DB::table('staff_operations')->where('id', $id)->first();
            if (!$row || !in_array($row->status, ['queued', 'running'], true)) {
                return;
            }
            $member = DB::table('staff_accounts')->where('user_id', $row->actor_id)->where('enabled', true)->first();
            $actor = $row->actor_id ? User::find($row->actor_id) : null;
            $ability = self::TYPES[$row->type][0] ?? null;
            if (!$member || !$actor || $actor->status !== 'active' || !$actor->email_verified_at || !$actor->onboarded_at
                || (int) $member->version !== (int) $row->membership_version || !$ability || !Permissions::allows($member->role, $ability)
                || $row->environment !== config('billing.environment') || $row->expires_at <= Sessions::timestamp()) {
                self::finish($row, 'cancelled', 'authorization_expired');

                return;
            }
            DB::table('staff_operations')->where('id', $id)->whereIn('status', ['queued', 'running'])->update([
                'status' => 'running', 'started_at' => $row->started_at ?? Sessions::timestamp(), 'updated_at' => Sessions::timestamp(),
            ]);
            if (!DB::table('staff_operations')->where('id', $id)->where('status', 'running')->exists()) {
                return;
            }
            try {
                if ($row->type === 'privacy.retry') {
                    $retried = PrivacyRequests::retry((int) $row->target_id, (int) $row->actor_id, false);
                    if (!$retried && $row->status !== 'running') {
                        self::finish($row, 'no_op', 'request_no_longer_failed');

                        return;
                    }
                    PrivacyRequests::process((int) $row->target_id);
                    $status = DB::table('privacy_requests')->where('id', $row->target_id)->value('status');
                    $result = in_array($status, ['completed', 'ready'], true) ? ['completed', 'privacy_completed']
                        : ($status === 'expired' ? ['no_op', 'request_expired'] : ['failed', 'privacy_failed']);
                } else {
                    $result = Recovery::run($row, $member);
                }
                self::finish($row, ...$result);
            } catch (\Throwable) {
                self::finish($row, 'failed', $row->type === 'privacy.retry' ? 'privacy_failed' : 'recovery_failed');
            }
        });
    }

    private static function finish(object $row, string $status, string $code): void
    {
        DB::transaction(function () use ($row, $status, $code): void {
            // Account deletion can cancel work while a domain handler is in flight.
            $updated = DB::table('staff_operations')->where('id', $row->id)->whereIn('status', ['queued', 'running'])->update([
                'status' => $status, 'result_code' => $code,
                'completed_at' => $status === 'running' ? null : Sessions::timestamp(), 'updated_at' => Sessions::timestamp(),
            ]);
            if (!$updated || $status === 'running') {
                return;
            }
            AuditTrail::record('staff.operation.finished', [
                'operation_id' => (int) $row->id, 'type' => $row->type, 'target_id' => (int) $row->target_id,
                'status' => $status, 'result_code' => $code,
            ], $row->actor_id ? (int) $row->actor_id : null, 'staff');
            if ($row->type === 'export.generate' && in_array($status, ['failed', 'cancelled'], true)) {
                DB::table('staff_exports')->where('id', $row->target_id)->where('status', 'queued')
                    ->update(['status' => $status, 'error_code' => $code, 'updated_at' => Sessions::timestamp()]);
            }
        });
    }
}
