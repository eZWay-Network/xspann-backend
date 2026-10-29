<?php

namespace App\Jobs;

use App\Models\{Audio, Report, Video};
use App\Services\PrivacyRequests;
use Spark\Facades\DB;
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;

class FoundationMaintenance implements JobInterface
{
    use Dispatchable;

    public function handle(): void
    {
        foreach (['account_action_tokens', 'staff_sessions'] as $table) {
            DB::table($table)->where('expires_at', '<=', now()->toDateTimeString())->delete();
        }

        foreach (DB::table('privacy_requests')->whereIn('status', ['pending', 'processing', 'failed'])
            ->where('attempts', '<', 5)->where('updated_at', '<', now()->subMinutes(5)->toDateTimeString())->orderBy('id')->limit(100)->get() as $record) {
            // Touch before dispatch to avoid queue growth while workers are offline.
            DB::table('privacy_requests')->where('id', $record->id)->update(['updated_at' => now()->toDateTimeString()]);
            PrivacyRequests::dispatch($record->id);
        }

        foreach (DB::table('privacy_requests')->where('kind', 'export')->where('status', '!=', 'expired')->where('expires_at', '<=', now()->toDateTimeString())->limit(100)->get() as $record) {
            PrivacyRequests::removeExport($record->id);
        }

        foreach (Video::where('status', Video::STATUS_DELETED)->limit(100)->get() as $video) {
            (new DeleteVideo($video->id))->handle();
        }

        foreach (Audio::where('status', 'deleted')->limit(100)->get() as $audio) {
            (new DeleteAudio($audio->id))->handle();
        }

        DB::transaction(function (): void {
            DB::table('privacy_requests')->whereIn('status', ['completed', 'expired'])
                ->where('updated_at', '<', now()->subDays(config('policies.completed_privacy_retention_days', 30))->toDateTimeString())->delete();

            DB::table('audit_events')->where('created_at', '<', now()->subDays(config('policies.audit_retention_days', 365))->toDateTimeString())->delete();

            // Keep active restrictions and open appeals; closed unneeded evidence has a finite lifetime.
            Report::where('status', 'resolved')->where('updated_at', '<', now()->subDays(config('policies.closed_report_retention_days', 365))->toDateTimeString())
                ->whereNotIn('id', DB::table('moderation_decisions')->select('report_id')->where('active', true))
                ->whereNotIn('id', DB::table('moderation_decisions')->select('report_id')->whereIn('id', DB::table('moderation_appeals')->select('decision_id')->where('status', 'open')))
                ->delete();
        });
    }
}
