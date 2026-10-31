<?php

namespace App\Jobs;

use App\Models\{Audio, Report, Video};
use App\Services\PrivacyRequests;
use Spark\Facades\{DB, Log};
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

        foreach (DB::table('privacy_requests')->where('kind', 'export')->where('status', '!=', 'expired')->where('expires_at', '<=', now()->toDateTimeString())->orderByRaw('updated_at ASC, id ASC')->limit(100)->get() as $record) {
            $this->cleanup('export', $record->id, fn() => PrivacyRequests::removeExport($record->id));
        }

        foreach (Video::where('status', Video::STATUS_DELETED)->orderByRaw('updated_at ASC, id ASC')->limit(100)->get() as $video) {
            $this->cleanup('video', $video->id, fn() => (new DeleteVideo($video->id))->handle());
        }

        foreach (Audio::where('status', 'deleted')->orderByRaw('updated_at ASC, id ASC')->limit(100)->get() as $audio) {
            $this->cleanup('audio', $audio->id, fn() => (new DeleteAudio($audio->id))->handle());
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

    private function cleanup(string $type, int $id, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            // Preserve the row for the next pass and continue unrelated expiry/retention work.
            DB::table(match ($type) {
                'export' => 'privacy_requests',
                'video' => 'videos',
                'audio' => 'audios',
            })->where('id', $id)->update(['updated_at' => now()->toDateTimeString()]);

            Log::error('Foundation cleanup failed for {type} {id}.', [
                'type' => $type,
                'id' => $id,
                'exception' => $exception::class,
            ]);
        }
    }
}
