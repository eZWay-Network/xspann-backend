<?php

namespace App\Services\Staff;

use App\Http\Resources\Staff\Records;
use App\Models\Report;
use App\Services\{AuditTrail, Communications, Moderation, ReleaseMedia};
use Spark\Facades\{DB, Hash};
use Spark\Http\{Request, Response};

final class Cases
{
    public static function independent(Context $context, Report $report): bool
    {
        return !in_array((int) $context->user->id, array_map('intval', [$report->user_id, $report->subject_user_id, $report->initiator_id]), true);
    }

    public static function evidence(Context $context, int $id): array
    {
        $context->authorize('reports.evidence.view');
        $report = Report::findOrFail($id);
        abort_unless(self::independent($context, $report), 403, 'An independent reviewer must open this evidence.');
        AuditTrail::record('moderation.evidence_viewed', ['report_id' => $id], $context->user->id, 'staff');
        $snapshot = json_decode($report->evidence, true, flags: JSON_THROW_ON_ERROR);
        unset($snapshot['storage_path'], $snapshot['creative']['storage_path']);

        // Message and boost snapshots use the UTC domain clock. Media rights use the app storage clock.
        foreach (['created_at', 'starts_at', 'ends_at', 'rights_confirmed_at'] as $field) {
            if (isset($snapshot[$field])) {
                $snapshot[$field] = Records::date($snapshot[$field], in_array($report->target_type, ['message', 'boost'], true) ? 'UTC' : null);
            }
        }

        return ['snapshot' => $snapshot, 'details' => $report->details,
            'media_url' => in_array($report->target_type, ['video', 'audio', 'boost'], true)
                ? (string) route('staff.reports.media', ['report' => $id, 'asset' => 'original']) : null];
    }

    public static function media(Context $context, int $id, string $asset, Request $request): Response
    {
        $context->authorize('reports.evidence.view');
        $report = Report::findOrFail($id);
        abort_unless($asset === 'original' && self::independent($context, $report), 403, 'This case does not authorize that preview.');
        $snapshot = json_decode($report->evidence, true, flags: JSON_THROW_ON_ERROR);
        $reference = match ($report->target_type) {
            'video', 'audio' => $snapshot['storage_path'] ?? null,
            'boost' => $snapshot['creative']['storage_path'] ?? null,
            default => null,
        };
        abort_unless(is_string($reference) && $reference !== '', 404, 'Original media is unavailable.');
        AuditTrail::record('moderation.media_viewed', ['report_id' => $id], $context->user->id, 'staff');

        return ReleaseMedia::deliver($reference, $request);
    }

    public static function create(Context $context, array $input): Report
    {
        $context->authorize('content.case.create');
        $report = Moderation::staffCase($context->user, $input);
        AuditTrail::record('moderation.case_created', ['report_id' => $report->id], $context->user->id, 'staff');

        return $report;
    }

    public static function assign(Context $context, int $id, array $input): void
    {
        $context->authorize('reports.assign');
        Communications::transaction(function () use ($context, $id, $input): void {
            $report = Report::whereKey($id)->lockForUpdate()->firstOrFail();
            $workflow = DB::table('report_workflows')->where('report_id', $id)->lockForUpdate()->first();
            abort_unless((int) ($workflow->version ?? 0) === (int) $input['version'], 409, 'Assignment changed. Refresh this case.');
            $assignee = empty($input['assignee_id']) ? null : (int) $input['assignee_id'];
            if ($assignee) {
                $member = DB::table('staff_accounts')->where('user_id', $assignee)->where('enabled', true)->first();
                $user = \App\Models\User::find($assignee);
                abort_unless($member && $user && $user->status === 'active' && $user->email_verified_at && $user->onboarded_at
                    && Permissions::allows($member->role, 'reports.decide')
                    && !in_array($assignee, array_map('intval', [$report->user_id, $report->subject_user_id, $report->initiator_id]), true), 422, 'Choose an eligible independent reviewer.');
            }
            DB::table('report_workflows')->upsert([
                'report_id' => $id, 'assignee_id' => $assignee, 'priority' => $input['priority'],
                'tag' => ($input['tag'] ?? '') ?: null, 'version' => (int) ($workflow->version ?? 0) + 1,
                'created_at' => Sessions::timestamp(), 'updated_at' => Sessions::timestamp(),
            ], ['report_id'], ['assignee_id', 'priority', 'tag', 'version', 'updated_at']);
            AuditTrail::record('moderation.assigned', [
                'report_id' => $id,
                'before' => ['assignee_id' => $workflow?->assignee_id, 'priority' => $workflow->priority ?? 'normal',
                    'tag' => $workflow?->tag, 'version' => (int) ($workflow->version ?? 0)],
                'after' => ['assignee_id' => $assignee, 'priority' => $input['priority'],
                    'tag' => ($input['tag'] ?? '') ?: null, 'version' => (int) ($workflow->version ?? 0) + 1],
            ], $context->user->id, 'staff');
        });
    }

    public static function note(Context $context, int $id, string $body): void
    {
        $context->authorize('reports.view');
        abort_unless($context->can('reports.assign') || $context->can('reports.decide'), 403);
        Report::findOrFail($id);
        DB::table('report_notes')->insert(['report_id' => $id, 'actor_id' => $context->user->id,
            'body' => Hash::encrypt(trim($body)), 'created_at' => Sessions::timestamp()]);
        AuditTrail::record('moderation.note_added', ['report_id' => $id], $context->user->id, 'staff');
    }
}
