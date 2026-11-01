<?php

namespace App\Services\Staff;

use App\Jobs\ProcessStaffOperation;
use App\Models\User;
use App\Services\{AuditTrail, PrivacyRequests};
use Spark\Facades\{Cache, DB};

final class Operations
{
    public static function privacyRetry(Context $context, int $id, array $input): int
    {
        $context->authorize('privacy.retry');
        $key = hash('sha256', $input['idempotency_key']);
        $persist = function () use ($context, $id, $input, $key): int {
            $fingerprint = hash('sha256', json_encode([$id, trim($input['reason']), config('billing.environment')], JSON_THROW_ON_ERROR));
            $old = DB::table('staff_operations')->where('actor_id', $context->user->id)->where('type', 'privacy.retry')->where('idempotency_hash', $key)->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $fingerprint), 409, 'This retry reference has different input.');

                return (int) $old->id;
            }
            $record = DB::table('privacy_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($record !== null, 404);
            abort_unless($record->status === 'failed', 409, 'Only a failed privacy request can be retried.');
            $pending = DB::table('staff_operations')->where('type', 'privacy.retry')->where('target_id', $id)->whereIn('status', ['queued', 'running'])->first();
            abort_if($pending !== null, 409, 'A retry is already queued for this request.');
            $operation = DB::table('staff_operations')->insert([
                'actor_id' => $context->user->id, 'membership_version' => $context->membership->version,
                'type' => 'privacy.retry', 'target_id' => $id, 'environment' => config('billing.environment'),
                'idempotency_hash' => $key, 'request_hash' => $fingerprint, 'status' => 'queued',
                'expires_at' => Sessions::timestamp(86400), 'created_at' => Sessions::timestamp(), 'updated_at' => Sessions::timestamp(),
            ]);
            AuditTrail::record('staff.operation.queued', ['operation_id' => $operation, 'request_id' => $id, 'reason' => trim($input['reason'])], $context->user->id, 'staff');

            return (int) $operation;
        };
        $operation = Cache::lock('staff.retry.' . $context->user->id . '.' . $key, 30)->block(5,
            fn () => Cache::lock("staff.privacy.$id", 30)->block(5, fn () => DB::transaction($persist)));

        try {
            ProcessStaffOperation::dispatch($operation)->onQueue('staff')->send();
        } catch (\Throwable) {
            // The durable queued record is recovered by StaffMaintenance.
        }

        return $operation;
    }

    public static function run(int $id): void
    {
        // Another worker owns this operation; maintenance will recover it if that worker stops.
        Cache::lock("staff.operation.$id", 3600)->get(function () use ($id): void {
            $row = DB::table('staff_operations')->where('id', $id)->first();
            if (!$row || !in_array($row->status, ['queued', 'running'], true)) {
                return;
            }
            $member = DB::table('staff_accounts')->where('user_id', $row->actor_id)->where('enabled', true)->first();
            $actor = $row->actor_id ? User::find($row->actor_id) : null;
            if (!$member || !$actor || $actor->status !== 'active' || !$actor->email_verified_at || !$actor->onboarded_at
                || (int) $member->version !== (int) $row->membership_version || !Permissions::allows($member->role, 'privacy.retry')
                || $row->type !== 'privacy.retry' || $row->environment !== config('billing.environment') || $row->expires_at <= Sessions::timestamp()) {
                self::finish($id, 'cancelled', 'authorization_expired');

                return;
            }
            DB::table('staff_operations')->where('id', $id)->update(['status' => 'running', 'started_at' => Sessions::timestamp(), 'updated_at' => Sessions::timestamp()]);
            try {
                $retried = PrivacyRequests::retry((int) $row->target_id, (int) $row->actor_id, false);
                if (!$retried && $row->status !== 'running') {
                    self::finish($id, 'no_op', 'request_no_longer_failed');

                    return;
                }
                PrivacyRequests::process((int) $row->target_id);
                $status = DB::table('privacy_requests')->where('id', $row->target_id)->value('status');
                self::finish($id, in_array($status, ['completed', 'ready'], true) ? 'completed' : ($status === 'expired' ? 'no_op' : 'failed'),
                    in_array($status, ['completed', 'ready'], true) ? 'privacy_completed' : ($status === 'expired' ? 'request_expired' : 'privacy_failed'));
            } catch (\Throwable) {
                self::finish($id, 'failed', 'privacy_failed');
            }
        });
    }

    private static function finish(int $id, string $status, string $code): void
    {
        DB::table('staff_operations')->where('id', $id)->update([
            'status' => $status, 'result_code' => $code, 'completed_at' => Sessions::timestamp(), 'updated_at' => Sessions::timestamp(),
        ]);
        AuditTrail::record('staff.operation.finished', ['operation_id' => $id, 'status' => $status, 'result_code' => $code], null, 'system');
    }
}
