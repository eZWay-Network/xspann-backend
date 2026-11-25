<?php

namespace App\Services;

use App\Models\{Artist, ArtistPlaylist, Audio, Comment, Notification, Report, User, Video};
use Spark\Facades\{Cache, DB};
use App\Services\Staff\{Context, Permissions, Sessions, Team};
use function count;
use function in_array;

class Moderation
{
    public const TARGETS = ['reported_user_id' => 'user', 'video_id' => 'video', 'audio_id' => 'audio', 'comment_id' => 'comment', 'playlist_id' => 'playlist', 'message_id' => 'message', 'announcement_id' => 'announcement', 'boost_id' => 'boost'];

    public static function report(User $reporter, array $input): Report
    {
        // Evidence authorization, capture and persistence must precede a competing privacy/retention deletion.
        return Communications::transaction(fn() => self::captureReport($reporter, $input));
    }

    public static function staffCase(User $staff, array $input): Report
    {
        abort_unless(in_array($input['target_type'], ['user', 'video', 'audio', 'comment', 'playlist', 'boost'], true), 422, 'Select public account or content metadata.');
        $field = array_search($input['target_type'], self::TARGETS, true);

        return Communications::transaction(fn () => self::captureReport($staff, [$field => $input['target_id'], 'reason' => mb_substr($input['reason'], 0, 255), 'details' => $input['details'] ?? $input['reason']], true));
    }

    private static function captureReport(User $reporter, array $input, bool $staffOrigin = false): Report
    {
        $targets = array_filter(array_intersect_key($input, self::TARGETS), fn($id) => $id !== null);

        abort_unless(count($targets) === 1, 422, 'Select exactly one supported report target.');

        $field = array_key_first($targets);
        $id = (int) $targets[$field];
        $type = self::TARGETS[$field];

        $viewer = $staffOrigin ? null : $reporter;
        $target = match ($type) {
            'user' => ($staffOrigin ? User::query() : User::visibleTo($viewer))->findOrFail($id),
            'video' => ($staffOrigin ? Video::query() : Video::published()->visibleTo($viewer))->findOrFail($id),
            'audio' => ($staffOrigin ? Audio::query() : Audio::availableTo($viewer))->findOrFail($id),
            'comment' => ($staffOrigin ? Comment::query() : Comment::visibleTo($viewer))->findOrFail($id),
            'playlist' => ($staffOrigin ? ArtistPlaylist::query() : ArtistPlaylist::visibleTo($viewer))->findOrFail($id),
            'message' => Conversations::reportedMessage($id, $reporter->id),
            'announcement' => Announcements::delivered($id, $reporter->id, true),
            'boost' => \App\Services\Promotions\Delivery::reportedBoost($id, $reporter),
        };

        if ($type === 'comment' && !$staffOrigin) {
            Video::published()->visibleTo($viewer)->findOrFail($target->video_id);
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
            'boost' => ['artist_id', 'video_id', 'surface', 'starts_at', 'ends_at', 'rights_version', 'rights_confirmed_at'],
        } as $key) {
            $snapshot[$key] = $type === 'announcement' && in_array($key, ['title', 'body'], true)
                ? \Spark\Facades\Hash::decrypt($target->$key) : $target->$key;
        }

        if ($type === 'boost') {
            $artist = Artist::findOrFail($target->artist_id);
            $video = Video::findOrFail($target->video_id);
            $snapshot['disclosure'] = 'Sponsored';
            $snapshot['sponsor'] = ['stage_name' => $artist->stage_name, 'slug' => $artist->slug];
            $snapshot['creative'] = ['caption' => $video->caption, 'storage_path' => $video->storage_path];
        }

        return Report::create([
            'user_id' => $staffOrigin ? null : $reporter->id,
            'source' => $staffOrigin ? 'staff' : 'customer',
            'initiator_id' => $staffOrigin ? $reporter->id : null,
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

    public const TABLES = [
        'user' => 'users', 'video' => 'videos', 'audio' => 'audios', 'comment' => 'comments',
        'playlist' => 'artist_playlists', 'message' => 'messages', 'announcement' => 'announcements', 'boost' => 'promotion_boosts',
    ];
    public const REASONS = ['safety', 'rights', 'spam', 'other'];
    public const ACTIONS = ['start_review', 'resolve', 'dismiss', 'quarantine', 'restrict', 'remove', 'soft_delete', 'disable_reuse', 'warn', 'suspend', 'ban', 'restore', 'revoke_sessions'];
    private const RESTRICTIONS = ['quarantine', 'restrict', 'remove', 'soft_delete', 'disable_reuse', 'suspend', 'ban'];

    public static function versionColumn(string $type): string
    {
        return in_array($type, ['video', 'playlist', 'announcement', 'boost'], true) ? 'version' : 'moderation_version';
    }

    public static function target(string $type, int $id, bool $lock = false): object
    {
        abort_unless(isset(self::TABLES[$type]), 422, 'Unsupported moderation target.');
        $query = DB::table(self::TABLES[$type])->where('id', $id);
        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public static function owner(string $type, object $target): int
    {
        return match ($type) {
            'user' => (int) $target->id,
            'playlist' => (int) Artist::findOrFail($target->artist_id)->user_id,
            default => (int) $target->user_id,
        };
    }

    public static function state(string $type, object $target): array
    {
        $state = ['status' => $type === 'user' ? $target->status : ($target->moderation_status ?? $target->status)];
        if (in_array($type, ['user', 'video'], true)) {
            $state['admin_deleted_at'] = $target->admin_deleted_at;
        }
        if ($type === 'audio') {
            $state['reuse_blocked'] = (bool) $target->reuse_blocked;
        }
        if ($type === 'video') {
            $state['publication_state'] = $target->publication_state;
            $state['publish_at'] = $target->publish_at;
        }
        return $state;
    }

    public static function actionChoices(Context $context, string $type, object $target): array
    {
        if (self::owner($type, $target) === $context->user->id) {
            return [];
        }
        if ($type === 'user' && DB::table('staff_accounts')->where('user_id', $target->id)->where('enabled', true)->exists()
            && !$context->can('staff.manage')) {
            return [];
        }
        $actions = $type === 'user'
            ? ['warn', 'suspend', 'ban', 'revoke_sessions', 'soft_delete']
            : ['quarantine', 'restrict', 'remove'];
        if ($type === 'video') {
            $actions[] = 'soft_delete';
        }
        if ($type === 'audio') {
            $actions[] = 'disable_reuse';
        }
        $choices = [];
        foreach ($actions as $action) {
            if ($context->can(self::ability($type, $action))) {
                $choices[] = ['value' => $action, 'label' => self::label($type, $action)];
            }
        }
        return $choices;
    }

    private static function label(string $type, string $action): string
    {
        return match ($action) {
            'quarantine' => 'Quarantine for review', 'restrict' => 'Restrict / hide',
            'remove' => match ($type) { 'audio' => 'Withdraw audio', 'message' => 'Mark unavailable', 'announcement' => 'Remove and stop delivery', default => 'Remove / unpublish' },
            'soft_delete' => 'Soft-delete ' . ($type === 'user' ? 'account' : 'video'),
            'disable_reuse' => 'Disable future reuse', 'revoke_sessions' => 'Revoke all sessions',
            'suspend' => 'Temporarily suspend', 'ban' => 'Permanently ban', default => ucfirst($action),
        };
    }

    private static function ability(string $type, string $action): string
    {
        if (in_array($action, ['start_review', 'resolve', 'dismiss'], true)) {
            return 'reports.decide';
        }
        if ($type !== 'user') {
            return 'content.moderate';
        }
        return match ($action) {
            'warn' => 'users.warn', 'suspend', 'restrict' => 'users.suspend', 'ban' => 'users.ban',
            'soft_delete' => 'users.delete', 'revoke_sessions' => 'users.sessions.revoke', default => 'users.suspend',
        };
    }

    private static function authorize(User $staff, string $ability): void
    {
        $member = DB::table('staff_accounts')->where('user_id', $staff->id)->where('enabled', true)->lockForUpdate()->first();
        abort_unless($member && Sessions::eligible(User::find($staff->id)) && Permissions::allows($member->role, $ability), 403, 'Your role cannot perform this action.');
    }

    private static function transaction(callable $callback): mixed
    {
        return Cache::lock('moderation.decisions', 30)->block(5, fn () => Team::locked(fn () => Communications::transaction($callback)));
    }

    public static function direct(Context $context, string $type, int $id, array $input): array
    {
        abort_unless(in_array($type, ['user', 'video', 'audio', 'comment', 'playlist'], true), 422, 'Use the reported evidence for this target.');
        return self::transaction(function () use ($context, $type, $id, $input): array {
            $context = Sessions::requireCurrent($context);
            abort_if(self::owner($type, self::target($type, $id)) === $context->user->id, 403, 'You cannot moderate yourself.');
            if ($input['action'] === 'restore') {
                $decision = DB::table('moderation_decisions')->where('id', (int) ($input['decision_id'] ?? 0))
                    ->where('target_type', $type)->where('target_id', $id)->firstOrFail();
                $report = Report::whereKey($decision->report_id)->lockForUpdate()->firstOrFail();
            } else {
                $report = self::staffCase($context->user, ['target_type' => $type, 'target_id' => $id, 'reason' => $input['reason']]);
            }
            return self::apply($report, $context->user, $input, $context);
        });
    }

    public static function decide(int $reportId, User $staff, array $input, ?Context $context = null): array
    {
        return self::transaction(function () use ($reportId, $staff, $input, $context): array {
            if ($context) {
                $context = Sessions::requireCurrent($context);
            }
            self::authorize($staff, 'reports.decide');
            $report = Report::whereKey($reportId)->lockForUpdate()->firstOrFail();
            abort_if($report->version !== (int) $input['version'], 409, 'This report has changed.');
            return self::apply($report, $staff, $input, $context);
        });
    }

    private static function apply(Report $report, User $staff, array $input, ?Context $context): array
    {
        $action = $input['action'];
        abort_unless(in_array($action, self::ACTIONS, true), 422, 'Choose a supported action.');
        abort_unless(in_array($input['reason_code'] ?? null, self::REASONS, true) && mb_strlen(trim($input['reason'] ?? '')) >= 5, 422, 'Give a reason code and explanation.');
        abort_if(in_array($staff->id, array_map('intval', [$report->user_id, $report->subject_user_id]), true), 403, 'You cannot review your own account, content or customer report.');
        abort_if($action !== 'restore' && !in_array($report->status, ['open', 'reviewing'], true), 409, 'This report is closed.');

        $type = in_array($action, ['warn', 'suspend', 'ban', 'revoke_sessions'], true) ? 'user' : $report->target_type;
        $id = $type === 'user' ? (int) $report->subject_user_id : (int) $report->target_id;
        $prior = null;
        if ($action === 'restore') {
            $prior = DB::table('moderation_decisions')->where('id', (int) ($input['decision_id'] ?? 0))
                ->where('report_id', $report->id)->where('active', true)->lockForUpdate()->first();
            abort_unless($prior !== null, 409, 'Select an active decision to restore.');
            abort_if(DB::table('moderation_appeals')->where('decision_id', $prior->id)->where('status', 'open')->exists(), 409, 'Resolve the pending appeal instead.');
            $type = $prior->target_type;
            $id = (int) $prior->target_id;
        }
        $target = DB::table(self::TABLES[$type])->where('id', $id)->first();
        $triage = in_array($action, ['start_review', 'resolve', 'dismiss'], true);
        abort_unless($target !== null || $triage, 409, 'This target is no longer available.');
        $owner = $target ? self::owner($type, $target) : (int) $report->subject_user_id;
        User::whereKey($owner)->lockForUpdate()->first();
        if ($target) {
            $target = self::target($type, $id, true);
        }
        self::authorize($staff, self::ability($type, $prior?->action ?? $action));
        if ($target) {
            self::protectAccount($staff, $type, $target, $prior?->action ?? $action, $context, $prior !== null);
        }
        $versionColumn = self::versionColumn($type);
        abort_if($target && isset($input['target_version']) && (int) $input['target_version'] !== (int) $target->$versionColumn, 409, 'The target changed. Reload before acting.');
        abort_if(!$triage && (($type === 'user' && $target->status === 'deleting') || (in_array($type, ['video', 'audio'], true) && $target->status === 'deleted')), 409, 'This target is pending permanent deletion.');
        abort_if($action === 'soft_delete' && !in_array($type, ['user', 'video'], true), 422);
        abort_if($action === 'disable_reuse' && $type !== 'audio', 422);
        abort_if($type === 'user' && in_array($action, ['quarantine', 'restrict', 'remove', 'disable_reuse'], true), 422, 'Choose a warning, suspension or ban for accounts.');
        $expires = null;
        if ($action === 'suspend') {
            $value = $input['expires_at'] ?? '';
            abort_unless(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/D', $value), 422, 'Supply a future suspension expiry with a timezone.');
            try {
                $date = new \DateTimeImmutable($value);
            } catch (\Exception) {
                abort(422, 'Invalid suspension expiry.');
            }
            abort_if(\DateTimeImmutable::getLastErrors() !== false, 422, 'Invalid suspension expiry.');
            abort_unless($date->getTimestamp() > time(), 422, 'Suspension expiry must be in the future.');
            $expires = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        $before = $target ? self::state($type, $target) : ['status' => 'unavailable'];
        if ($prior) {
            self::lift((int) $prior->id);
        }
        if ($action === 'dismiss') {
            foreach (DB::table('moderation_decisions')->where('report_id', $report->id)->where('action', 'quarantine')->where('active', true)->get() as $quarantine) {
                self::lift((int) $quarantine->id);
            }
        }
        $active = in_array($action, self::RESTRICTIONS, true);
        $decisionId = (int) DB::table('moderation_decisions')->insert([
            'report_id' => $report->id, 'staff_id' => $staff->id, 'subject_user_id' => $owner ?: null,
            'target_type' => $type, 'target_id' => $id, 'action' => $action, 'reason_code' => $input['reason_code'],
            'reason' => trim($input['reason']), 'active' => $active, 'expires_at' => $expires,
            'before_state' => json_encode($before, JSON_THROW_ON_ERROR), 'created_at' => now()->toDateTimeString(),
        ]);
        if ($active) {
            self::restrict($decisionId, $type, $id, $action);
        } elseif ($action === 'revoke_sessions') {
            self::revokeUserSessions($id);
        }
        if ($target) {
            DB::table(self::TABLES[$type])->where('id', $id)->update([$versionColumn => DB::raw("$versionColumn + 1")]);
        }
        $after = $target ? self::state($type, self::target($type, $id)) : $before;
        DB::table('moderation_decisions')->where('id', $decisionId)->update(['after_state' => json_encode($after, JSON_THROW_ON_ERROR)]);
        $status = match ($action) { 'dismiss' => 'dismissed', 'start_review', 'quarantine' => 'reviewing', default => 'resolved' };
        $report->update(['status' => $status, 'version' => $report->version + 1]);
        $audit = ['report_id' => $report->id, 'decision_id' => $decisionId, 'action' => $action,
            'target_type' => $type, 'target_id' => $id, 'subject_user_id' => $owner,
            'reason_code' => $input['reason_code'], 'reason' => trim($input['reason']), 'before' => $before, 'after' => $after, 'expires_at' => $expires];
        AuditTrail::record('moderation.decided', $audit, $staff->id, 'staff');
        if (!in_array($action, ['start_review', 'resolve', 'dismiss'], true)) {
            Notification::create(['user_id' => $owner, 'type' => 'moderation_decision',
                'data' => ['decision_id' => $decisionId, 'action' => $action, 'message' => trim($input['reason'])]]);
        }
        return ['report_id' => $report->id, 'decision_id' => $decisionId, 'status' => $status, 'version' => $report->version];
    }

    private static function protectAccount(User $staff, string $type, object $target, string $action, ?Context $context, bool $restoring = false): void
    {
        if ($type !== 'user' || in_array($action, ['start_review', 'resolve', 'dismiss'], true)) {
            return;
        }
        abort_if((int) $target->id === $staff->id, 403, 'You cannot moderate your own account.');
        $member = DB::table('staff_accounts')->where('user_id', $target->id)->lockForUpdate()->first();
        if ($member?->enabled) {
            self::authorize($staff, 'staff.manage');
            if (!$restoring) {
                Team::protectLastAdmin($member, $member->role, false);
            }
        }
        if ($action === 'soft_delete') {
            abort_unless($context && $context->recent(config('staff.confirmation_seconds')), 422, 'Confirm your password before deleting or restoring an account.');
        }
    }

    public static function revokeUserSessions(int $userId): void
    {
        Realtime::retire($userId);
        Sessions::revokeAll($userId);
        DB::table('jwt_access_tokens')->where('user_id', $userId)->delete();
        DB::table('account_action_tokens')->where('user_id', $userId)->delete();
        User::whereKey($userId)->update(['remember_token' => null]);
    }

    private static function restrict(int $decisionId, string $type, int $id, string $action): void
    {
        $pending = [[$type, $id]];
        $seen = [];
        while ($pending) {
            [$type, $id] = array_pop($pending);
            if (isset($seen["$type:$id"])) {
                continue;
            }
            $seen["$type:$id"] = true;
            DB::table('moderation_restrictions')->insert(['decision_id' => $decisionId, 'target_type' => $type, 'target_id' => $id]);
            self::refreshTarget($type, $id);
            if ($action === 'disable_reuse') {
                continue;
            }
            $children = match ($type) {
                'user' => [['video', 'videos', 'user_id'], ['audio', 'audios', 'user_id'], ['comment', 'comments', 'user_id']],
                'video' => [['audio', 'audios', 'source_video_id']],
                'audio' => [['video', 'videos', 'audio_id']],
                'comment' => [['comment', 'comments', 'parent_id']],
                default => [],
            };
            foreach ($children as [$childType, $table, $column]) {
                foreach (DB::table($table)->where($column, $id)->pluck('id') as $childId) {
                    $pending[] = [$childType, (int) $childId];
                }
            }
        }
    }

    private static function refreshTarget(string $type, int $id): void
    {
        $row = DB::table(self::TABLES[$type])->where('id', $id)->lockForUpdate()->first();
        if (!$row) {
            return;
        }
        $decisions = DB::table('moderation_decisions')->where('active', true)
            ->whereIn('id', DB::table('moderation_restrictions')->select('decision_id')->where('target_type', $type)->where('target_id', $id))->get();
        $actions = array_column($decisions->all(), 'action');
        $deleted = false;
        foreach ($decisions as $decision) {
            if ($decision->action === 'soft_delete' && $decision->target_type === $type && (int) $decision->target_id === $id) {
                $deleted = true;
            }
        }
        $version = self::versionColumn($type);
        $values = [$version => DB::raw("$version + 1")];
        if ($type === 'user') {
            if ($row->status === 'deleting') {
                return;
            }
            $values['status'] = $deleted ? 'deleted' : (in_array('ban', $actions, true) ? 'banned' : ($actions ? 'suspended' : 'active'));
            $values['admin_deleted_at'] = $deleted ? ($row->admin_deleted_at ?? Sessions::timestamp()) : null;
            if ($values['status'] !== 'active') {
                self::revokeUserSessions($id);
            }
        } elseif ($type !== 'boost') {
            $values['moderation_status'] = array_intersect($actions, ['remove', 'soft_delete']) ? 'removed'
                : (array_intersect($actions, ['restrict', 'suspend', 'ban']) ? 'restricted' : (in_array('quarantine', $actions, true) ? 'reviewing' : 'approved'));
            if ($type === 'video') {
                $values['admin_deleted_at'] = $deleted ? ($row->admin_deleted_at ?? Sessions::timestamp()) : null;
                if ($values['moderation_status'] !== 'approved' && $row->publication_state === 'scheduled') {
                    $values += ['publication_state' => 'draft', 'publication_intent' => 'draft', 'publish_at' => null,
                        'publish_timezone' => null, 'release_error' => 'moderation_restricted',
                        'status' => $row->processing_state === 'ready' ? 'draft' : $row->status];
                }
            }
            if ($type === 'audio') {
                $values['reuse_blocked'] = in_array('disable_reuse', $actions, true);
            }
        }
        DB::table(self::TABLES[$type])->where('id', $id)->update($values);
        if (array_diff($actions, ['disable_reuse']) || $type === 'boost') {
            \App\Services\Promotions\Invalidations::record($type, $id);
        }
        if ($type === 'message') {
            DB::table('conversations')->where('id', $row->conversation_id)->update(['version' => DB::raw('version + 1')]);
            $current = DB::table('conversations')->where('id', $row->conversation_id)->first();
            foreach (DB::table('conversation_participants')->where('conversation_id', $row->conversation_id)->pluck('user_id') as $recipient) {
                Realtime::record((int) $recipient, 'conversation.updated', $row->conversation_id, (int) $current->version);
            }
        }
        if ($type === 'announcement') {
            if ($values['moderation_status'] !== 'approved') {
                DB::table('announcement_deliveries')->where('announcement_id', $id)->where('status', 'pending')->update(['status' => 'suppressed']);
                DB::table('announcements')->where('id', $id)->where('status', 'queued')->update(['status' => 'completed']);
            }
            foreach (DB::table('announcement_deliveries')->where('announcement_id', $id)->where('status', 'delivered')->pluck('user_id') as $recipient) {
                Realtime::record((int) $recipient, 'notifications.updated', (int) $recipient, 0);
            }
        }
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

    public static function expireSuspensions(?int $userId = null): void
    {
        $query = DB::table('moderation_decisions')->where('action', 'suspend')->where('active', true)->where('expires_at', '<=', Sessions::timestamp());
        if ($userId !== null) {
            $query->where('target_type', 'user')->where('target_id', $userId);
        }
        foreach ($query->orderBy('id')->limit(100)->pluck('id') as $id) {
            self::transaction(function () use ($id): void {
                $decision = DB::table('moderation_decisions')->where('id', $id)->where('active', true)->lockForUpdate()->first();
                if (!$decision || !$decision->expires_at || $decision->expires_at > Sessions::timestamp()) {
                    return;
                }
                $target = DB::table('users')->where('id', $decision->target_id)->lockForUpdate()->first();
                $before = $target ? self::state('user', $target) : ['status' => 'unavailable'];
                self::lift((int) $id);
                AuditTrail::record('moderation.suspension_expired', ['report_id' => $decision->report_id, 'decision_id' => $id,
                    'target_type' => 'user', 'target_id' => $decision->target_id, 'subject_user_id' => $decision->subject_user_id,
                    'reason_code' => 'other', 'reason' => 'Suspension expired.', 'before' => $before,
                    'after' => $target ? self::state('user', self::target('user', (int) $target->id)) : $before], null, 'system');
            });
        }
    }

    public static function appeal(User $user, int $decisionId, string $explanation): array
    {
        return self::transaction(function () use ($user, $decisionId, $explanation): array {
            $decision = DB::table('moderation_decisions')->where('id', $decisionId)->where('subject_user_id', $user->id)->first();
            abort_unless($decision !== null, 404, 'Decision not found.');
            abort_unless($decision->active && in_array($decision->action, self::RESTRICTIONS, true), 422, 'This decision is not appealable.');
            abort_if(DB::table('moderation_appeals')->where('decision_id', $decisionId)->exists(), 409, 'An appeal already exists.');
            $id = DB::table('moderation_appeals')->insert(['decision_id' => $decisionId, 'user_id' => $user->id,
                'explanation' => $explanation, 'status' => 'open', 'version' => 1,
                'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString()]);
            AuditTrail::record('moderation.appealed', ['decision_id' => $decisionId, 'appeal_id' => $id, 'report_id' => $decision->report_id,
                'target_type' => $decision->target_type, 'target_id' => $decision->target_id, 'subject_user_id' => $user->id], $user->id);
            return ['id' => $id, 'status' => 'open', 'version' => 1];
        });
    }

    public static function decideAppeal(int $appealId, User $staff, array $input, ?Context $context = null): array
    {
        return self::transaction(function () use ($appealId, $staff, $input, $context): array {
            if ($context) {
                $context = Sessions::requireCurrent($context);
            }
            self::authorize($staff, 'appeals.decide');
            abort_unless(in_array($input['outcome'], ['uphold', 'overturn'], true)
                && in_array($input['reason_code'] ?? null, self::REASONS, true) && mb_strlen(trim($input['reason'])) >= 5, 422);
            $appeal = DB::table('moderation_appeals')->where('id', $appealId)->lockForUpdate()->firstOrFail();
            abort_if($appeal->status !== 'open' || (int) $appeal->version !== (int) $input['version'], 409, 'This appeal has changed.');
            $decision = DB::table('moderation_decisions')->where('id', $appeal->decision_id)->firstOrFail();
            $report = Report::findOrFail($decision->report_id);
            abort_if(in_array($staff->id, array_map('intval', [$decision->staff_id, $appeal->user_id, $report->user_id]), true), 403, 'An independent reviewer must decide this appeal.');
            $target = DB::table(self::TABLES[$decision->target_type])->where('id', $decision->target_id)->lockForUpdate()->first();
            $before = $target ? self::state($decision->target_type, $target) : ['status' => 'unavailable'];
            if ($input['outcome'] === 'overturn') {
                abort_unless($target && !in_array($target->status ?? null, ['deleting', 'deleted'], true) || ($target && $decision->target_type === 'user' && $target->admin_deleted_at), 409, 'This target was permanently deleted.');
                self::protectAccount($staff, $decision->target_type, $target, $decision->action, $context, true);
                self::authorize($staff, self::ability($decision->target_type, $decision->action));
                self::lift((int) $decision->id);
            }
            $after = $target ? self::state($decision->target_type, self::target($decision->target_type, (int) $decision->target_id)) : $before;
            DB::table('moderation_appeals')->where('id', $appealId)->update([
                'status' => $input['outcome'] === 'overturn' ? 'overturned' : 'upheld', 'reviewer_id' => $staff->id,
                'resolution' => trim($input['reason']), 'version' => $appeal->version + 1, 'updated_at' => now()->toDateTimeString(),
            ]);
            AuditTrail::record('moderation.appeal_decided', ['appeal_id' => $appealId, 'report_id' => $report->id,
                'decision_id' => $decision->id, 'outcome' => $input['outcome'], 'target_type' => $decision->target_type,
                'target_id' => $decision->target_id, 'subject_user_id' => $appeal->user_id,
                'reason_code' => $input['reason_code'], 'reason' => trim($input['reason']), 'before' => $before, 'after' => $after], $staff->id, 'staff');
            if ($appeal->user_id) {
                Notification::create(['user_id' => $appeal->user_id, 'type' => 'moderation_appeal',
                    'data' => ['appeal_id' => $appealId, 'outcome' => $input['outcome'], 'message' => trim($input['reason'])]]);
            }
            return ['id' => $appealId, 'outcome' => $input['outcome'], 'version' => $appeal->version + 1];
        });
    }
}
