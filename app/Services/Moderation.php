<?php

namespace App\Services;

use App\Models\{Artist, ArtistPlaylist, Audio, Comment, Notification, Report, User, Video};
use Spark\Facades\{Cache, DB};
use function count;
use function in_array;

class Moderation
{
    public const TARGETS = ['reported_user_id' => 'user', 'video_id' => 'video', 'audio_id' => 'audio', 'comment_id' => 'comment', 'playlist_id' => 'playlist', 'message_id' => 'message', 'announcement_id' => 'announcement'];

    public static function report(User $reporter, array $input): Report
    {
        // Evidence authorization, capture and persistence must precede a competing privacy/retention deletion.
        return Communications::transaction(fn() => self::captureReport($reporter, $input));
    }

    private static function captureReport(User $reporter, array $input): Report
    {
        $targets = array_filter(array_intersect_key($input, self::TARGETS), fn($id) => $id !== null);

        abort_unless(count($targets) === 1, 422, 'Select exactly one supported report target.');

        $field = array_key_first($targets);
        $id = (int) $targets[$field];
        $type = self::TARGETS[$field];

        $target = match ($type) {
            'user' => User::visibleTo($reporter)->findOrFail($id),
            'video' => Video::published()->visibleTo($reporter)->findOrFail($id),
            'audio' => Audio::availableTo($reporter)->findOrFail($id),
            'comment' => Comment::visibleTo($reporter)->findOrFail($id),
            'playlist' => ArtistPlaylist::visibleTo($reporter)->findOrFail($id),
            'message' => Conversations::reportedMessage($id, $reporter->id),
            'announcement' => Announcements::delivered($id, $reporter->id, true),
        };

        if ($type === 'comment') {
            Video::published()->visibleTo($reporter)->findOrFail($target->video_id);
        }

        $subject = match ($type) {
            'user' => $id,
            'playlist' => (int) Artist::findOrFail($target->artist_id)->user_id,
            default => (int) $target->user_id,
        };
        abort_if($subject === $reporter->id, 422, 'You cannot report your own account or content.');

        $snapshot = ['type' => $type, 'id' => $id];
        foreach (match ($type) {
            'user' => ['username', 'name', 'bio'],
            'video' => ['caption', 'storage_path', 'audio_id', 'rights_version', 'rights_confirmed_at'],
            'audio' => ['title', 'storage_path', 'source_video_id', 'rights_version', 'rights_confirmed_at'],
            'comment' => ['body', 'video_id', 'parent_id'],
            'playlist' => ['artist_id', 'title', 'description', 'genre_id', 'category', 'visibility'],
            'message' => ['conversation_id', 'body', 'created_at'],
            'announcement' => ['artist_id', 'title', 'body', 'video_id'],
        } as $key) {
            $snapshot[$key] = $type === 'announcement' && in_array($key, ['title', 'body'], true)
                ? \Spark\Facades\Hash::decrypt($target->$key) : $target->$key;
        }

        return Report::create([
            'user_id' => $reporter->id,
            'subject_user_id' => $subject,
            $field => $id,
            'target_type' => $type,
            'target_id' => $id,
            'reason' => $input['reason'],
            'details' => $input['details'] ?? null,
            'evidence' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'status' => 'open',
            'version' => 1,
        ]);
    }

    public static function decide(int $reportId, User $staff, array $input): array
    {
        return Cache::lock('moderation.decisions', 30)->block(5, fn() => Communications::transaction(function () use ($reportId, $staff, $input): array {
            $report = Report::whereKey($reportId)->lockForUpdate()->firstOrFail();
            $restoring = $input['action'] === 'restore';

            abort_if(
                $report->status !== ($restoring ? 'resolved' : 'open') || $report->version !== $input['version'],
                409,
                'This report has changed.'
            );

            abort_if(
                in_array($staff->id, [$report->user_id, $report->subject_user_id], true),
                403,
                'Another staff member must review this report.'
            );

            if ($restoring) {
                $prior = DB::table('moderation_decisions')->where('report_id', $reportId)->where('action', 'restrict')->where('active', true)->get();

                abort_unless(
                    count($prior) > 0,
                    422,
                    'This report has no active restriction.'
                );

                foreach ($prior as $decision) {
                    abort_if(
                        DB::table('moderation_appeals')->where('decision_id', $decision->id)->where('status', 'open')->exists(),
                        409,
                        'Resolve the open appeal through its independent reviewer.'
                    );

                    self::lift($decision->id);
                }
            }

            $decisionId = DB::table('moderation_decisions')->insert([
                'report_id' => $report->id,
                'staff_id' => $staff->id,
                'subject_user_id' => $report->subject_user_id,
                'action' => $input['action'],
                'reason' => $input['reason'],
                'active' => $input['action'] === 'restrict',
                'created_at' => now()->toDateTimeString(),
            ]);

            if ($input['action'] === 'restrict') {
                self::restrict($decisionId, $report->target_type, (int) $report->target_id);
            }

            $report->update(['status' => 'resolved', 'version' => $report->version + 1]);

            AuditTrail::record('moderation.decided', ['report_id' => $reportId, 'decision_id' => $decisionId, 'action' => $input['action']], $staff->id, 'staff');

            if ($report->subject_user_id) {
                Notification::create([
                    'user_id' => $report->subject_user_id,
                    'type' => 'moderation_decision',
                    'data' => ['decision_id' => $decisionId, 'action' => $input['action'], 'message' => $input['reason']],
                ]);
            }

            return ['decision_id' => $decisionId, 'status' => 'resolved', 'version' => $report->version];
        }));
    }

    private static function restrict(int $decisionId, string $type, int $id): void
    {
        $pending = [[$type, $id]];
        $seen = [];
        while ($pending) {
            [$type, $id] = array_pop($pending);
            $key = "$type:$id";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            DB::table('moderation_restrictions')->insert(['decision_id' => $decisionId, 'target_type' => $type, 'target_id' => $id]);
            self::refreshTarget($type, $id);

            if ($type === 'user') {
                foreach (Video::where('user_id', $id)->get() as $video) {
                    $pending[] = ['video', (int) $video->id];
                }
                foreach (Audio::where('user_id', $id)->get() as $audio) {
                    $pending[] = ['audio', (int) $audio->id];
                }
                foreach (Comment::where('user_id', $id)->get() as $comment) {
                    $pending[] = ['comment', (int) $comment->id];
                }
            } elseif ($type === 'video') {
                foreach (Audio::where('source_video_id', $id)->get() as $audio) {
                    $pending[] = ['audio', (int) $audio->id];
                }
            } elseif ($type === 'audio') {
                foreach (Video::where('audio_id', $id)->get() as $video) {
                    $pending[] = ['video', (int) $video->id];
                }
            } elseif ($type === 'comment') {
                foreach (Comment::where('parent_id', $id)->get() as $comment) {
                    $pending[] = ['comment', (int) $comment->id];
                }
            }
        }
    }

    private static function refreshTarget(string $type, int $id): void
    {
        $restricted = DB::table('moderation_restrictions')->where('target_type', $type)->where('target_id', $id)->exists();
        if ($type === 'user') {
            User::whereKey($id)->whereIn('status', ['active', 'suspended'])->update(['status' => $restricted ? 'suspended' : 'active']);
            DB::table('staff_sessions')->where('user_id', $id)->delete();
            if ($restricted) {
                Realtime::retire($id);
            }
            return;
        }

        if (in_array($type, ['message', 'announcement'], true)) {
            $table = $type === 'message' ? 'messages' : 'announcements';
            $row = DB::table($table)->where('id', $id)->first();
            $status = $restricted ? 'restricted' : 'approved';
            if (!$row || $row->moderation_status === $status) {
                return;
            }
            DB::table($table)->where('id', $id)->update(['moderation_status' => $status]);
            if ($type === 'message') {
                DB::table('conversations')->where('id', $row->conversation_id)->update(['version' => DB::raw('version + 1')]);
                $version = (int) DB::table('conversations')->where('id', $row->conversation_id)->value('version');
                foreach (DB::table('conversation_participants')->where('conversation_id', $row->conversation_id)->pluck('user_id') as $recipient) {
                    Realtime::record((int) $recipient, 'conversation.updated', $row->conversation_id, $version);
                }
            } else {
                DB::table('announcements')->where('id', $id)->update(['version' => DB::raw('version + 1')]);
                foreach (DB::table('announcement_deliveries')->where('announcement_id', $id)->where('status', 'delivered')->pluck('user_id') as $recipient) {
                    Realtime::record((int) $recipient, 'notifications.updated', (int) $recipient, 0);
                }
            }
            return;
        }

        $model = match ($type) { 'video' => Video::class, 'audio' => Audio::class, 'comment' => Comment::class, 'playlist' => ArtistPlaylist::class};
        $model::whereKey($id)->update(['moderation_status' => $restricted ? 'restricted' : 'approved']);
    }

    private static function lift(int $decisionId): void
    {
        $targets = DB::table('moderation_restrictions')->where('decision_id', $decisionId)->get();
        DB::table('moderation_restrictions')->where('decision_id', $decisionId)->delete();
        DB::table('moderation_decisions')->where('id', $decisionId)->update(['active' => false]);
        foreach ($targets as $target) {
            self::refreshTarget($target->target_type, (int) $target->target_id);
        }
    }

    public static function appeal(User $user, int $decisionId, string $explanation): array
    {
        return Cache::lock('moderation.decisions', 30)->block(5, fn() => Communications::transaction(function () use ($user, $decisionId, $explanation): array {
            $decision = DB::table('moderation_decisions')->where('id', $decisionId)->where('subject_user_id', $user->id)->first();

            abort_unless(
                $decision !== null,
                404,
                'Decision not found.'
            );

            abort_unless(
                $decision->active && $decision->action === 'restrict',
                422,
                'This decision is not appealable.'
            );

            abort_if(
                DB::table('moderation_appeals')->where('decision_id', $decisionId)->exists(),
                409,
                'An appeal already exists for this decision.'
            );

            $id = DB::table('moderation_appeals')->insert([
                'decision_id' => $decisionId,
                'user_id' => $user->id,
                'explanation' => $explanation,
                'status' => 'open',
                'version' => 1,
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ]);
            AuditTrail::record('moderation.appealed', ['decision_id' => $decisionId, 'appeal_id' => $id], $user->id);

            return ['id' => $id, 'status' => 'open', 'version' => 1];
        }));
    }

    public static function decideAppeal(int $appealId, User $staff, array $input): array
    {
        return Cache::lock('moderation.decisions', 30)->block(5, fn() => Communications::transaction(function () use ($appealId, $staff, $input): array {
            $appeal = DB::table('moderation_appeals')->where('id', $appealId)->lockForUpdate()->first();

            abort_unless(
                $appeal !== null,
                404,
                'Appeal not found.'
            );

            abort_if(
                $appeal->status !== 'open' || (int) $appeal->version !== $input['version'],
                409,
                'This appeal has changed.'
            );

            $decision = DB::table('moderation_decisions')->where('id', $appeal->decision_id)->first();
            $report = Report::findOrFail($decision->report_id);

            abort_if(
                in_array($staff->id, [(int) $decision->staff_id, (int) $appeal->user_id, (int) $report->user_id], true),
                403,
                'An independent staff member must review this appeal.'
            );

            if ($input['outcome'] === 'overturn') {
                self::lift($decision->id);
            }

            DB::table('moderation_appeals')->where('id', $appealId)->update([
                'status' => $input['outcome'] === 'overturn' ? 'overturned' : 'upheld',
                'reviewer_id' => $staff->id,
                'resolution' => $input['reason'],
                'version' => $appeal->version + 1,
                'updated_at' => now()->toDateTimeString(),
            ]);
            AuditTrail::record('moderation.appeal_decided', ['appeal_id' => $appealId, 'outcome' => $input['outcome']], $staff->id, 'staff');

            return ['id' => $appealId, 'outcome' => $input['outcome'], 'version' => $appeal->version + 1];
        }));
    }
}
