<?php

namespace App\Services;

use App\Http\Resources\CommunicationResource;
use App\Models\Notification;
use App\Services\Billing\Billing;
use Spark\Facades\DB;

class CommunicationPrivacy
{
    public static function export(int $userId): array
    {
        $conversations = DB::table('conversation_participants')->where('user_id', $userId)->pluck('conversation_id');
        $messages = $conversations ? DB::table('messages')->whereIn('conversation_id', $conversations)->orderBy('id')->get()->all() : [];
        $announcements = DB::table('announcements')->where('user_id', $userId)->orderBy('id')->get()->all();

        return [
            'preferences' => Communications::preferences($userId),
            'messages' => array_map([CommunicationResource::class, 'message'], $messages),
            'subscriptions' => DB::table('artist_update_subscriptions')->select(['artist_id', 'subscribed', 'consent_version', 'revision', 'updated_at'])->where('user_id', $userId)->get()->toArray(),
            'consent_history' => DB::table('communication_consent_history')->select(['artist_id', 'purpose', 'channel', 'source', 'enabled', 'consent_version', 'revision', 'created_at'])->where('user_id', $userId)->get()->toArray(),
            'authored_announcements' => array_map(fn($row) => CommunicationResource::announcement($row), $announcements),
        ];
    }

    /** Called before cascading account deletion; remove cross-account alert references too. */
    public static function deleting(int $userId): void
    {
        $conversations = DB::table('conversation_participants')->where('user_id', $userId)->pluck('conversation_id');
        $announcements = DB::table('announcements')->where('user_id', $userId)->pluck('id');
        self::pruneNotices($conversations, $announcements);
        Realtime::retire($userId);
    }

    public static function maintain(): void
    {
        Communications::transaction(function (): void {
            $cutoff = Billing::timestamp(time() - config('communications.retention_days') * 86400);
            // Report evidence has its own encrypted, audited retention lifecycle.
            // Sending an old saved draft starts its retention clock and preserves the current period's quota.
            $expiredAnnouncements = fn() => DB::table('announcements')->whereRaw('COALESCE(accepted_at, created_at) < ?', [$cutoff]);
            $announcements = $expiredAnnouncements()->pluck('id');
            $conversations = array_unique(DB::table('messages')->where('created_at', '<', $cutoff)->pluck('conversation_id'));
            self::pruneNotices([], $announcements, $conversations ? $cutoff : null);
            $expiredAnnouncements()->delete();
            DB::table('messages')->where('created_at', '<', $cutoff)->delete();
            foreach ($conversations as $conversationId) {
                DB::table('conversations')->where('id', $conversationId)->update(['version' => DB::raw('version + 1')]);
                $version = (int) DB::table('conversations')->where('id', $conversationId)->value('version');
                foreach (DB::table('conversation_participants')->where('conversation_id', $conversationId)->pluck('user_id') as $recipient) {
                    Realtime::record((int) $recipient, 'conversation.updated', (int) $conversationId, $version);
                }
            }
            DB::table('communication_idempotency')->where('created_at', '<', $cutoff)->delete();
            DB::table('communication_consent_history')->where('created_at', '<', $cutoff)->delete();
            DB::table('realtime_sessions')->where('expires_at', '<=', Billing::timestamp())->delete();
            DB::table('realtime_sessions')->whereNull('retired_at')
                ->whereNotIn('user_id', DB::table('users')->select('id')->where('status', 'active')->whereNotNull('email_verified_at')->whereNotNull('onboarded_at'))
                ->update(['retired_at' => Billing::timestamp()]);
            DB::table('realtime_outbox')->where('created_at', '<', Billing::timestamp(time() - config('communications.outbox_retention_days') * 86400))->delete();
        });
    }

    private static function pruneNotices(array $conversations, array $announcements, ?string $messageCutoff = null): void
    {
        if (!$conversations && !$announcements && $messageCutoff === null) {
            return;
        }
        $last = 0;
        do {
            $rows = Notification::whereIn('type', ['direct_message', 'artist_announcement'])->where('id', '>', $last)->orderBy('id')->limit(200)->get();
            $messageIds = [];
            foreach ($rows as $row) {
                if ($row->type === 'direct_message') {
                    $messageIds[] = $row->data['message_id'] ?? 0;
                }
            }
            $expired = $messageCutoff !== null && $messageIds
                ? DB::table('messages')->whereIn('id', $messageIds)->where('created_at', '<', $messageCutoff)->pluck('id') : [];
            $changedUsers = [];
            foreach ($rows as $row) {
                $last = $row->id;
                if (in_array($row->data['conversation_id'] ?? null, $conversations, true)
                    || in_array($row->data['announcement_id'] ?? null, $announcements, true)
                    || in_array($row->data['message_id'] ?? null, $expired, true)) {
                    $row->remove();
                    $changedUsers[$row->user_id] = true;
                }
            }
            foreach (array_keys($changedUsers) as $userId) {
                Realtime::record((int) $userId, 'notifications.updated', (int) $userId, 0);
            }
        } while (count($rows) === 200);
    }
}
