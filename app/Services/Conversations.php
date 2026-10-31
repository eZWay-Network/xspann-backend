<?php

namespace App\Services;

use App\Models\Notification;
use App\Services\Billing\Billing;
use Spark\Facades\{DB, Hash};

class Conversations
{
    public static function participant(int $conversationId, int $userId): object
    {
        $row = DB::table('conversation_participants')->where('conversation_id', $conversationId)->where('user_id', $userId)->first();
        abort_unless($row !== null, 404, 'Conversation not found.');

        return $row;
    }

    private static function permissionQuery(): \Spark\Database\QueryBuilder
    {
        return DB::table('users as a')->join('users as b', 'a.id', '!=', 'b.id')
            ->leftJoin('communication_preferences as ap', 'ap.user_id', '=', 'a.id')
            ->leftJoin('communication_preferences as bp', 'bp.user_id', '=', 'b.id')
            ->selectRaw('1')->where('a.status', 'active')->where('b.status', 'active')
            ->whereNotNull('a.email_verified_at')->whereNotNull('b.email_verified_at')
            ->whereNotNull('a.onboarded_at')->whereNotNull('b.onboarded_at')
            ->whereRaw('COALESCE(ap.allow_direct_messages, 1) = 1 AND COALESCE(bp.allow_direct_messages, 1) = 1')
            ->whereExists(DB::table('follows as f')->selectRaw('1')->whereColumn('f.follower_id', 'a.id')->whereColumn('f.following_id', 'b.id'))
            ->whereExists(DB::table('follows as f')->selectRaw('1')->whereColumn('f.follower_id', 'b.id')->whereColumn('f.following_id', 'a.id'))
            ->whereNotExists(DB::table('blocks as block')->selectRaw('1')->where(fn($q) => $q
                ->where(fn($q) => $q->whereColumn('block.blocker_id', 'a.id')->whereColumn('block.blocked_id', 'b.id'))
                ->orWhere(fn($q) => $q->whereColumn('block.blocker_id', 'b.id')->whereColumn('block.blocked_id', 'a.id'))));
    }

    public static function canSend(int $a, int $b): bool
    {
        return self::permissionQuery()->where('a.id', $a)->where('b.id', $b)->exists();
    }

    public static function inboxQuery(int $viewer): \Spark\Database\QueryBuilder
    {
        $participant = fn() => DB::table('conversation_participants as p')
            ->whereColumn('p.conversation_id', 'conversations.id')->where('p.user_id', $viewer);

        return DB::table('conversations')->select('conversations.*')
            ->whereExists($participant()->selectRaw('1'))
            ->selectSub($participant()->select('last_read_message_id'), 'last_read_message_id')
            ->selectSub($participant()->select('muted'), 'muted')
            ->selectSub(self::permissionQuery()->whereColumn('a.id', 'conversations.low_user_id')
                ->whereColumn('b.id', 'conversations.high_user_id'), 'can_send')
            ->selectSub(DB::table('messages as m')->selectRaw('MAX(m.id)')->whereColumn('m.conversation_id', 'conversations.id'), 'last_message_id')
            ->selectSub(DB::table('messages as m')->join('conversation_participants as p', 'p.conversation_id', '=', 'm.conversation_id')
                ->selectRaw('COUNT(*)')->whereColumn('m.conversation_id', 'conversations.id')->where('p.user_id', $viewer)
                ->where('m.user_id', '!=', $viewer)->where('m.moderation_status', 'approved')->whereColumn('m.id', '>', 'p.last_read_message_id'), 'unread_count');
    }

    public static function alertMessageIds(int $viewer, array $ids): array
    {
        return DB::table('messages')->whereIn('id', $ids)->where('moderation_status', 'approved')
            ->whereIn('conversation_id', self::inboxQuery($viewer)->select('conversations.id')
                ->whereExists(self::permissionQuery()->whereColumn('a.id', 'conversations.low_user_id')
                    ->whereColumn('b.id', 'conversations.high_user_id')))->pluck('id');
    }

    public static function hydrate(array $rows): array
    {
        $ids = array_filter(array_map(fn($row) => $row->last_message_id, $rows));
        $messages = $ids ? DB::table('messages')->whereIn('id', $ids)->get()->keyBy('id')->all() : [];
        foreach ($rows as $row) {
            $row->last_message = $messages[$row->last_message_id] ?? null;
        }

        return $rows;
    }

    public static function state(int $id, int $viewer): object
    {
        $row = self::inboxQuery($viewer)->where('id', $id)->first();
        abort_unless($row !== null, 404, 'Conversation not found.');

        return self::hydrate([$row])[0];
    }

    public static function open(int $userId, int $recipient): object
    {
        return Communications::transaction(function () use ($userId, $recipient): object {
            abort_unless(self::canSend($userId, $recipient), 403, 'Messaging requires eligible mutual followers who allow messages.');
            $pair = ['low_user_id' => min($userId, $recipient), 'high_user_id' => max($userId, $recipient)];
            $existing = DB::table('conversations')->where($pair)->first();
            if ($existing) {
                return $existing;
            }
            abort_if(DB::table('conversations')->where('created_by', $userId)
                ->where('created_at', '>', Billing::timestamp(time() - 3600))->count() >= config('communications.conversations_per_hour'), 429, 'Conversation creation limit reached.');
            $id = DB::table('conversations')->insert([
                ...$pair,
                'created_by' => $userId,
                'created_at' => Billing::timestamp(),
            ]);
            foreach ([$userId, $recipient] as $participant) {
                DB::table('conversation_participants')->insert([
                    'conversation_id' => $id,
                    'user_id' => $participant,
                    'muted' => Communications::preferences($participant)['mute_new_conversations'],
                ]);
                Realtime::record($participant, 'conversation.updated', $id, 1);
            }

            return DB::table('conversations')->where('id', $id)->first();
        });
    }

    public static function send(int $conversationId, int $userId, string $body, string $clientId): array
    {
        return Communications::transaction(function () use ($conversationId, $userId, $body, $clientId): array {
            self::participant($conversationId, $userId);
            $conversation = DB::table('conversations')->where('id', $conversationId)->first();
            $other = (int) ($conversation->low_user_id == $userId ? $conversation->high_user_id : $conversation->low_user_id);
            abort_unless(self::canSend($userId, $other), 403, 'Messaging is no longer permitted.');
            $clientId = strtolower($clientId);
            $hash = hash_hmac('sha256', json_encode([$conversationId, $body], JSON_THROW_ON_ERROR), config('app.key'));
            $existing = DB::table('messages')->where('user_id', $userId)->where('client_message_id', $clientId)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This client_message_id was already used with different input.');

                return [$existing, false];
            }
            abort_if(DB::table('messages')->where('user_id', $userId)->where('created_at', '>', Billing::timestamp(time() - 60))
                ->count() >= config('communications.sends_per_minute'), 429, 'Message send limit reached.');
            $id = DB::table('messages')->insert([
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'body' => Hash::encrypt($body),
                'client_message_id' => $clientId,
                'request_hash' => $hash,
                'created_at' => Billing::timestamp(),
            ]);
            $version = (int) $conversation->version + 1;
            DB::table('conversations')->where('id', $conversationId)->update(['version' => $version]);
            foreach ([$userId, $other] as $participant) {
                Realtime::record($participant, 'message.created', $id, $version);
            }
            $recipient = self::participant($conversationId, $other);
            if (!$recipient->muted && Communications::preferences($other)['message_alerts_enabled']) {
                Notification::create([
                    'user_id' => $other,
                    'type' => 'direct_message',
                    'data' => ['conversation_id' => $conversationId, 'message_id' => $id, 'message' => 'You have a new message.'],
                ]);
            }

            return [DB::table('messages')->where('id', $id)->first(), true];
        });
    }

    public static function read(int $conversationId, int $userId, int $messageId): object
    {
        return Communications::transaction(function () use ($conversationId, $userId, $messageId): object {
            $participant = self::participant($conversationId, $userId);
            abort_unless(DB::table('messages')->where('conversation_id', $conversationId)->where('id', $messageId)->exists(), 422, 'The read cursor must belong to this conversation.');
            if ($messageId > $participant->last_read_message_id) {
                DB::table('conversation_participants')->where('id', $participant->id)->update(['last_read_message_id' => $messageId]);
                DB::table('conversations')->where('id', $conversationId)->update(['version' => DB::raw('version + 1')]);
                $version = (int) DB::table('conversations')->where('id', $conversationId)->value('version');
                Realtime::record($userId, 'conversation.updated', $conversationId, $version);
            }
            NotificationReads::conversation($userId, $conversationId, $messageId);

            return DB::table('conversations')->where('id', $conversationId)->first();
        });
    }

    public static function mute(int $conversationId, int $userId, bool $muted): object
    {
        return Communications::transaction(function () use ($conversationId, $userId, $muted): object {
            $participant = self::participant($conversationId, $userId);
            if ((bool) $participant->muted !== $muted) {
                DB::table('conversation_participants')->where('id', $participant->id)->update(['muted' => $muted]);
                DB::table('conversations')->where('id', $conversationId)->update(['version' => DB::raw('version + 1')]);
                Realtime::record($userId, 'conversation.updated', $conversationId, (int) DB::table('conversations')->where('id', $conversationId)->value('version'));
            }

            return DB::table('conversations')->where('id', $conversationId)->first();
        });
    }

    public static function reportedMessage(int $id, int $viewer): object
    {
        $message = DB::table('messages')->where('id', $id)->first();
        abort_unless($message !== null, 404, 'Message not found.');
        self::participant($message->conversation_id, $viewer);
        $message->body = Hash::decrypt($message->body);

        return $message;
    }
}
