<?php

namespace App\Services\Staff;

use App\Models\User;
use App\Queries\Staff\{Finance, OperationalHealth, Overview};
use App\Services\AuditTrail;
use Spark\Facades\{Cache, DB, Storage};

final class Exports
{
    public const SOURCES = ['dashboard' => 'analytics.platform.view', 'finance' => 'analytics.finance.view', 'operations' => 'operations.view'];

    public static function create(Context $context, array $input): int
    {
        $context->authorize('exports.create');
        $context->authorize(self::SOURCES[$input['type']]);
        $hash = hash('sha256', $input['idempotency_key']);
        [$id, $operation] = Cache::lock('staff.export.' . $context->user->id . '.' . $hash, 30)->block(5,
            fn () => DB::transaction(function () use ($context, $input, $hash): array {
                $current = Sessions::requireCurrent($context);
                $current->authorize(self::SOURCES[$input['type']]);
                $old = DB::table('staff_exports')->where('actor_id', $current->user->id)->where('idempotency_hash', $hash)->first();
                if ($old) {
                    $previous = json_decode($old->filters, true, flags: JSON_THROW_ON_ERROR);
                    // Retrying a preset after midnight must keep the original resolved dates.
                    $sameRange = $previous['period'] === $input['period']
                        && ($input['period'] !== 'custom' || ($previous['from'] === ($input['from'] ?? null) && $previous['to'] === ($input['to'] ?? null)));
                    abort_unless($old->type === $input['type'] && $sameRange
                        && $old->environment === config('billing.environment'), 409, 'This export reference has different input.');
                    $id = (int) $old->id;
                } else {
                    $range = Overview::range($input, $current->preferences()['timezone']);
                    if (isset($input['timezone']) && ($range['timezone'] !== $input['timezone']
                        || $range['from'] !== ($input['from'] ?? null) || $range['to'] !== ($input['to'] ?? null))) {
                        throw new StaffException('The reporting dates or timezone changed. Refresh the report before exporting.', 409, 'period');
                    }
                    $id = (int) DB::table('staff_exports')->insert([
                        'actor_id' => $current->user->id, 'membership_version' => $current->membership->version,
                        'type' => $input['type'], 'environment' => config('billing.environment'),
                        'filters' => json_encode($range, JSON_THROW_ON_ERROR), 'idempotency_hash' => $hash,
                        'status' => 'queued', 'expires_at' => Sessions::timestamp(86400),
                        'created_at' => Sessions::timestamp(), 'updated_at' => Sessions::timestamp(),
                    ]);
                    AuditTrail::record('staff.export.created', ['export_id' => $id, 'type' => $input['type']], $current->user->id, 'staff');
                }
                $operation = Operations::enqueue($current, 'export.generate', $id,
                    ['reason' => 'Export ' . $input['type'] . ' report', 'idempotency_key' => $input['idempotency_key']], false);

                return [$id, $operation];
            }));
        Operations::dispatch($operation);

        return $id;
    }

    public static function owned(Context $context, int $id): object
    {
        $context->authorize('exports.create');
        $row = DB::table('staff_exports')->where('id', $id)->where('actor_id', $context->user->id)
            ->where('environment', config('billing.environment'))->firstOrFail();
        $context->authorize(self::SOURCES[$row->type] ?? 'invalid');
        abort_unless((int) $row->membership_version === (int) $context->membership->version, 403);

        return $row;
    }

    public static function path(int $id): string
    {
        return 'staff-exports/' . $id . '.csv';
    }

    public static function generate(int $id, int $actorId, object $member): array
    {
        return Cache::lock("staff.export.file.$id", 300)->block(5, function () use ($id, $actorId, $member): array {
            $row = DB::table('staff_exports')->where('id', $id)->where('actor_id', $actorId)->first();
            if (!$row || $row->expires_at <= Sessions::timestamp() || $row->environment !== config('billing.environment')
                || (int) $row->membership_version !== (int) $member->version || !Permissions::allows($member->role, self::SOURCES[$row->type] ?? 'invalid')) {
                return ['cancelled', 'export_authorization_expired'];
            }
            if ($row->status === 'ready') {
                return ['completed', 'export_ready'];
            }
            $context = new Context(User::find($actorId), $member, (object) []);
            // Presets were resolved when requested; crossing midnight must not move this report.
            $saved = json_decode($row->filters, true, flags: JSON_THROW_ON_ERROR);
            $range = Overview::range([...$saved, 'period' => 'custom'], $saved['timezone'] ?? 'UTC');
            $records = self::rows($context, $row->type, $range);
            abort_if(count($records) > 10000, 422, 'Report exceeds the export limit.');
            $stream = fopen('php://temp', 'w+');
            try {
                fputcsv($stream, ['section', 'metric', 'value', 'currency', 'as_of_or_from', 'until', 'environment'], ',', '"', '');
                foreach ($records as $record) {
                    fputcsv($stream, array_map(self::cell(...), [...$record, $row->environment]), ',', '"', '');
                }
                rewind($stream);
                $csv = stream_get_contents($stream);
                abort_unless(Storage::disk('local')->put(self::path($id), $csv), 503, 'Report storage is unavailable.');
            } finally {
                fclose($stream);
            }
            // Revocation or deletion during generation must not publish an artifact.
            $fresh = DB::table('staff_accounts')->where('user_id', $actorId)->where('enabled', true)->first();
            if (!$fresh || !Sessions::eligible(User::find($actorId)) || (int) $fresh->version !== (int) $row->membership_version
                || !Permissions::allows($fresh->role, 'exports.create') || !Permissions::allows($fresh->role, self::SOURCES[$row->type])) {
                Storage::disk('local')->delete(self::path($id));

                return ['cancelled', 'export_authorization_expired'];
            }
            $published = DB::table('staff_exports')->where('id', $id)->where('actor_id', $actorId)
                ->where('status', 'queued')->where('expires_at', '>', Sessions::timestamp())->update([
                'status' => 'ready', 'row_count' => count($records), 'updated_at' => Sessions::timestamp(),
            ]);
            if (!$published) {
                Storage::disk('local')->delete(self::path($id));

                return ['cancelled', 'export_expired'];
            }

            return ['completed', 'export_ready'];
        });
    }

    public static function rows(Context $context, string $type, array $range): array
    {
        $context->authorize(self::SOURCES[$type]);
        if ($type === 'finance') {
            $report = Finance::read($context, $range);
            $rows = [];
            foreach ($report['invoices'] as $invoice) {
                $rows[] = ['paid_invoices_issued', 'tax_inclusive_minor_units', $invoice['amount_paid'], $invoice['currency'], $report['bounds']['from'], $report['bounds']['until']];
                $rows[] = ['paid_invoices_issued', 'invoice_count', $invoice['count'], $invoice['currency'], $report['bounds']['from'], $report['bounds']['until']];
            }
            foreach ($report['adjustments'] as $adjustment) {
                $rows[] = ['adjustments_created', implode(':', [$adjustment['kind'], $adjustment['status'], $adjustment['allocation']]),
                    $adjustment['amount'], $adjustment['currency'], $report['bounds']['from'], $report['bounds']['until']];
            }
            foreach ($report['paid_access'] as $plan) {
                $rows[] = ['current_paid_access', $plan['plan'], $plan['accounts'], '', $report['generated_at'], ''];
            }
            $rows[] = ['current_reconciliation', 'older_than_24_hours', $report['reconciliation_gaps'], '', $report['generated_at'], ''];

            return $rows;
        }
        if ($type === 'operations') {
            $health = OperationalHealth::read();
            $rows = [['queue_metadata', 'availability', $health['queue_availability'], '', $health['generated_at'], '']];
            foreach ($health['queues'] as $queue) {
                foreach (['pending', 'processing', 'failed'] as $state) {
                    $rows[] = ['queue:' . $queue['queue'], $state, $queue[$state], '', $health['generated_at'], ''];
                }
            }
            foreach ($health['domains'] as $domain) {
                $rows[] = ['domain_backlog', $domain['label'], $domain['count'], '', $health['generated_at'], ''];
                $rows[] = ['domain_oldest', $domain['label'], $domain['oldest_at'], '', $health['generated_at'], ''];
            }
            foreach ($health['workers'] as $worker) {
                $rows[] = ['worker', $worker['task'], $worker['status'], '', $worker['succeeded_at'], ''];
            }

            return $rows;
        }
        $overview = Overview::read($context, $range);
        $rows = [];
        foreach ($overview['metrics'] as $metric) {
            $rows[] = ['current_snapshot', $metric['label'], $metric['value'], '', $overview['generated_at'], ''];
        }
        foreach ($overview['chart']['series'] as $day) {
            $rows[] = ['retained_registrations', 'records', $day['value'], '', $day['date'], ''];
        }

        return $rows;
    }

    public static function cell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u', $text) ? "'" . $text : $text;
    }

    public static function maintain(): void
    {
        foreach (DB::table('staff_exports')->where('status', '!=', 'expired')
            ->where(fn ($query) => $query->where('expires_at', '<=', Sessions::timestamp())->orWhereNull('actor_id'))
            ->orderBy('id')->limit(100)->get() as $row) {
            Cache::lock("staff.export.file.$row->id", 300)->get(function () use ($row): void {
                $disk = Storage::disk('local');
                if ($disk->exists(self::path((int) $row->id))) {
                    abort_unless($disk->delete(self::path((int) $row->id)), 503, 'Report cleanup is unavailable.');
                }
                DB::table('staff_exports')->where('id', $row->id)->update(['status' => 'expired', 'updated_at' => Sessions::timestamp()]);
            });
        }
        DB::table('staff_exports')->where('status', 'expired')->where('updated_at', '<', Sessions::timestamp(-30 * 86400))->delete();
    }

    public static function deleting(int $user): void
    {
        DB::table('staff_exports')->where('actor_id', $user)->update(['expires_at' => Sessions::timestamp()]);
        DB::table('staff_operations')->where('actor_id', $user)->whereIn('status', ['queued', 'running'])
            ->update(['status' => 'cancelled', 'result_code' => 'account_deleted', 'completed_at' => Sessions::timestamp()]);
    }
}
