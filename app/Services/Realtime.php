<?php

namespace App\Services;

use App\Models\{Notification, User};
use App\Services\Billing\Billing;
use Spark\Facades\{Auth, Cache, DB};
use Spark\Relay\Facades\Relay;
use Spark\Relay\Protocol;

class Realtime
{
    public const TYPES = ['message.created', 'conversation.updated', 'notification.created', 'notifications.updated', 'release.updated', 'entitlements.updated', 'boost.updated', 'placement.updated'];

    /** The caller's transaction commits the resource and hint together. No network I/O here. */
    public static function record(int $userId, string $type, int $resourceId, int $version): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported realtime event.');
        }
        $id = DB::table('realtime_outbox')->insert([
            'user_id' => $userId,
            'type' => $type,
            'resource_id' => $resourceId,
            'version' => $version,
            'created_at' => Billing::timestamp(),
            'available_at' => Billing::timestamp(),
        ]);
        if ($version === 0) {
            DB::table('realtime_outbox')->where('id', $id)->update(['version' => $id]);
        }
    }

    private static function configured(): void
    {
        abort_unless(config('relay.apps.0.key') && config('relay.apps.0.secret'), 503, 'Realtime is not configured; continue using REST.');
    }

    public static function register(int $userId): array
    {
        self::configured();

        return Communications::transaction(function () use ($userId): array {
            $token = DB::table('jwt_access_tokens')->where('user_id', $userId)->where('token_hash', Auth::token())
                ->where('expire_at', '>', now()->toDateTimeString())->first();
            abort_unless($token && Communications::eligible($userId), 401, 'A current login token is required.');
            $row = DB::table('realtime_sessions')->where('token_hash', $token->token_hash)->first();
            // A retired login cannot reopen its old channel, including after suspension is lifted.
            abort_if($row && $row->retired_at, 401, 'Sign in again to create a realtime session.');
            if (!$row) {
                $id = DB::table('realtime_sessions')->insert([
                    'user_id' => $userId,
                    'token_hash' => $token->token_hash,
                    'channel_key' => bin2hex(random_bytes(32)),
                    'expires_at' => \Spark\Carbon::parse($token->expire_at)->setTimezone('UTC')->toDateTimeString(),
                    'created_at' => Billing::timestamp(),
                ]);
                $row = DB::table('realtime_sessions')->where('id', $id)->first();
            }

            return [
                'key' => config('relay.apps.0.key'),
                'host' => config('relay.public.host'),
                'port' => config('relay.public.port'),
                'tls' => config('relay.public.tls'),
                'auth_url' => home_url('/api/v1/broadcasting/auth'),
                'channel' => 'private-session.' . $row->channel_key,
                'expires_at' => Billing::date($row->expires_at),
            ];
        });
    }

    public static function authorize(int $userId, string $channel, string $socket): string
    {
        self::configured();
        abort_unless(Protocol::validSocketId($socket) && Protocol::validChannel($channel), 422, 'Invalid socket or channel.');
        $row = self::sessions($userId)->where('t.token_hash', Auth::token())->where('r.channel_key', substr($channel, strlen('private-session.')))->first();
        abort_unless(str_starts_with($channel, 'private-session.') && $row, 403, 'This channel does not belong to the current login.');

        return Relay::authorize($channel, $socket);
    }

    private static function sessions(int $userId): \Spark\Database\QueryBuilder
    {
        return DB::table('realtime_sessions as r')
            ->join('jwt_access_tokens as t', 't.token_hash', '=', 'r.token_hash')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->select('r.*')->where('r.user_id', $userId)->whereColumn('t.user_id', 'r.user_id')
            ->whereNull('r.retired_at')->where('r.expires_at', '>', Billing::timestamp())
            ->where('t.expire_at', '>', now()->toDateTimeString())
            ->where('u.status', 'active')->whereNotNull('u.email_verified_at')->whereNotNull('u.onboarded_at');
    }

    public static function retire(int $userId): void
    {
        DB::table('realtime_sessions')->where('user_id', $userId)->whereNull('retired_at')->update(['retired_at' => Billing::timestamp()]);
    }

    public static function permitted(object $event): bool
    {
        if (!Communications::eligible($event->user_id)) {
            return false;
        }
        if ($event->type === 'message.created' || $event->type === 'conversation.updated') {
            $message = $event->type === 'message.created' ? DB::table('messages')->where('id', $event->resource_id)->first() : null;
            $id = $event->type === 'message.created' ? $message?->conversation_id : $event->resource_id;
            $conversation = $id ? DB::table('conversations')->where('id', $id)->first() : null;
            return $conversation && (!$message || $message->moderation_status === 'approved')
                && in_array((int) $event->user_id, [(int) $conversation->low_user_id, (int) $conversation->high_user_id], true)
                && Conversations::canSend($conversation->low_user_id, $conversation->high_user_id);
        }
        if ($event->type === 'notification.created') {
            $notice = Notification::whereKey($event->resource_id)->where('user_id', $event->user_id)->first();
            if ($notice && $notice->type === 'direct_message') {
                $participant = DB::table('conversation_participants')->where('conversation_id', $notice->data['conversation_id'] ?? 0)->where('user_id', $notice->user_id)->first();
                if (!$participant || $participant->muted || !Communications::preferences($notice->user_id)['message_alerts_enabled']) {
                    return false;
                }
            }

            return $notice && self::notificationPermitted($notice);
        }
        if ($event->type === 'release.updated') {
            return DB::table('videos')->where('id', $event->resource_id)->where('user_id', $event->user_id)->where('status', '!=', 'deleted')->exists();
        }

        return in_array($event->type, ['notifications.updated', 'entitlements.updated'], true);
    }

    public static function notificationPermitted(Notification $notice): bool
    {
        $data = $notice->data;
        if ($notice->type === 'direct_message') {
            $message = DB::table('messages')->where('id', $data['message_id'] ?? 0)->first();
            return $message && $message->moderation_status === 'approved'
                && DB::table('conversation_participants')->where('conversation_id', $message->conversation_id)->where('user_id', $notice->user_id)->exists()
                && Conversations::canSend($message->user_id, $notice->user_id);
        }
        if ($notice->type === 'artist_announcement') {
            $row = DB::table('announcements')->where('id', $data['announcement_id'] ?? 0)->first();
            return $row && Announcements::visible($row, $notice->user_id)
                && DB::table('announcement_deliveries')->where('announcement_id', $row->id)->where('user_id', $notice->user_id)->where('status', 'delivered')->exists();
        }
        $viewer = User::find($notice->user_id);
        if (!$viewer) {
            return false;
        }
        SocialNotifications::redact([$notice], $viewer);

        return ($notice->data['message'] ?? '') !== 'This activity is no longer available.';
    }

    public static function drain(): void
    {
        foreach (DB::table('realtime_outbox')->whereNull('completed_at')->where('available_at', '<=', Billing::timestamp())->orderBy('id')->limit(100)->pluck('id') as $id) {
            try {
                self::publish((int) $id);
            } catch (\Throwable) {
                // Contention or one damaged event must not stop unrelated pending hints.
                \Spark\Facades\Log::error('Realtime event {id} remains pending for recovery.', ['id' => $id]);
            }
        }
    }

    public static function publish(int $id): void
    {
        Cache::lock("realtime.outbox.$id", 120)->block(1, function () use ($id): void {
            $event = DB::table('realtime_outbox')->where('id', $id)->whereNull('completed_at')->first();
            if (!$event) {
                return;
            }
            try {
                if (self::permitted($event)) {
                    $hint = [
                        'event_id' => (string) $event->id,
                        'type' => $event->type,
                        'occurred_at' => Billing::date($event->created_at),
                        'resource_id' => (int) $event->resource_id,
                        'version' => (int) $event->version,
                    ];
                    foreach (self::sessions($event->user_id)->get() as $session) {
                        // Refresh immediately before each network call; an already in-flight hint contains no content.
                        if (!self::sessions($event->user_id)->where('r.id', $session->id)->exists() || !self::permitted($event)) {
                            continue;
                        }
                        self::configured();
                        Relay::trigger('private-session.' . $session->channel_key, $event->type, $hint);
                    }
                }
                DB::table('realtime_outbox')->where('id', $id)->update(['completed_at' => Billing::timestamp()]);
            } catch (\Throwable) {
                DB::table('realtime_outbox')->where('id', $id)->update([
                    'attempts' => $event->attempts + 1,
                    'available_at' => Billing::timestamp(time() + min(300, 5 * (2 ** min(6, (int) $event->attempts)))),
                ]);
            }
        });
    }
}
