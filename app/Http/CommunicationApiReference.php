<?php

namespace App\Http;

use App\Http\Controllers\Api\V1\AnnouncementController;
use App\Services\Communications;

class CommunicationApiReference
{
    public static function catalog(): array
    {
        $result = [];
        $add = function ($action, $title, $description, $response, $rules = [], $example = [], $status = '200', $cursor = false) use (&$result): void {
            $group = str_starts_with($action, 'Realtime') ? 'Realtime' : (str_starts_with($action, 'Announcement') ? 'Artist announcements' : 'Messaging and preferences');
            $limit = null;
            $errors = ['409' => 'Stale version, immutable accepted content or idempotency conflict.', '422' => 'Invalid input; writes reject unknown fields.', '429' => 'Per-account send, conversation creation or paid-period quota exhausted.'];
            if ($cursor) {
                $rules += ['limit' => ['sometimes', 'integer', 'min:1', 'max:50'], 'cursor' => ['sometimes', 'string', 'max:200']];
            }
            $result[$action] = compact('group', 'title', 'description', 'response', 'rules', 'example', 'status', 'limit', 'errors', 'cursor');
        };
        $date = '2026-10-31T12:00:00Z';
        $preferences = [...Communications::DEFAULTS, 'updates_revision' => 1];
        $message = ['id' => 1, 'conversation_id' => 1, 'sender_user_id' => 1, 'client_message_id' => '9eb30d77-eab2-4fa4-9344-f6f9bfbd83eb', 'body' => 'Hello', 'unavailable' => false, 'created_at' => $date];
        $conversation = ['id' => 1, 'recipient_user_id' => 2, 'version' => 2, 'can_send' => true, 'last_read_message_id' => 0, 'muted' => false, 'unread_count' => 1, 'last_message' => $message, 'created_at' => $date];
        $announcement = ['id' => 1, 'artist_id' => 1, 'title' => 'New release', 'body' => 'My latest original song', 'video_id' => null, 'version' => 1, 'created_at' => $date];
        $owned = [...$announcement, 'status' => 'draft', 'moderation_status' => 'approved', 'recipient_count' => 0, 'recipient_estimate' => 1, 'delivered_count' => 0, 'suppressed_count' => 0, 'accepted_at' => null];
        $received = [...$announcement, 'read_at' => null, 'delivered_at' => $date];
        $subscription = ['subscribed' => false, 'consent_version' => null, 'current_consent_version' => config('communications.consent_version'), 'revision' => 0, 'channel' => 'in_app', 'artist_updates_enabled' => true];
        $add('CommunicationController@preferences', 'Communication preferences', 'Own settings only. Global artist updates enabled is a suppression switch, not artist consent. Per-artist subscription starts false. Defaults apply until explicitly changed.', $preferences);
        $add('CommunicationController@updatePreferences', 'Change communication preferences', 'Explicit JSON booleans only. Disabling messages stops new sends in both directions. Global update changes invalidate old pending consent snapshots, even when re-enabled. Alert and mute defaults do not erase history.', $preferences, array_fill_keys(array_keys(Communications::DEFAULTS), ['sometimes', 'boolean']), ['artist_updates_enabled' => false]);
        $add('ConversationController@index', 'Your conversations', 'Participant inbox ordered by immutable created_at and id descending. Default 20/max 50; URL-encode next_cursor. Includes current per-conversation and total unread message counts. Reading does not mark messages read.', $conversation, cursor: true);
        $add('ConversationController@store', 'Open a conversation', 'Free. Requires eligible mutual followers and both messaging preferences enabled. Returns the canonical pair, including on retries. Maximum 10 newly created conversations per rolling hour per creator.', $conversation, ['recipient_user_id' => ['required', 'integer', 'min:1']], ['recipient_user_id' => 2]);
        $add('ConversationController@show', 'Conversation detail', 'Participants only, including after unfollow/block so stored history remains reportable. can_send reflects current mutual follow, block, preferences and account eligibility.', $conversation);
        $add('ConversationController@messages', 'Message history or catch-up', 'Participants only. Use before_id for descending older history or after_id for ascending reconnect catch-up; never both. Default 20/max 50. Use next_after_id until has_more=false. Restricted message bodies return null. No read side effect.', $message, ['before_id' => ['sometimes', 'integer', 'min:1'], 'after_id' => ['sometimes', 'integer', 'min:0'], 'limit' => ['sometimes', 'integer', 'min:1', 'max:50']]);
        $add('ConversationController@send', 'Send a text message', 'Free, participant-only. Rechecks permission on every send. UTF-8 body up to 2000 characters and a client-generated UUID. Same sender/UUID/input returns 200; different input returns 409. New writes return 201. Maximum 30 new messages per rolling minute per sender across all conversations. No edits, unsend, attachments or client publishing.', $message, ['body' => ['required', 'string', 'max:2000'], 'client_message_id' => ['required', 'string', 'max:36']], ['body' => 'Hello', 'client_message_id' => $message['client_message_id']], '201');
        $add('ConversationController@read', 'Advance message read cursor', 'The ID must belong to this conversation. Cursor only increases. Marks linked message alerts through that ID read. Never changes the other participant read cursor.', $conversation, ['last_read_message_id' => ['required', 'integer', 'min:1']], ['last_read_message_id' => 1]);
        $add('ConversationController@preferences', 'Mute conversation alerts', 'Own muted flag only. Suppresses future alerts; stored messages, unread state and realtime synchronization remain available.', $conversation, ['muted' => ['required', 'boolean']], ['muted' => true]);
        $add('CommunicationController@subscription', 'Your artist update consent', 'Own consent only; following, chatting and buying never enroll a user. Returns current consent wording version. No subscriber identities or contact exports.', $subscription);
        $add('CommunicationController@subscribe', 'Opt into or out of artist updates', 'Explicit subscribed boolean. Opt-in requires current consent_version and an eligible, unblocked artist. Opt-out remains available after a block or artist restriction. Only in-app delivery; opting back in never revives an older campaign.', $subscription, ['subscribed' => ['required', 'boolean'], 'consent_version' => ['sometimes', 'string', 'max:80']], ['subscribed' => true, 'consent_version' => config('communications.consent_version')]);
        $add('AnnouncementController@index', 'Your announcement drafts and jobs', 'Owner-only history with aggregate estimates and delivery counts, without recipient identities. Available after downgrade. Stable creation cursor, default 20/max 50.', $owned, cursor: true);
        $add('AnnouncementController@store', 'Create an announcement draft', 'Pro owner only. Title 120/body 2000 UTF-8 characters; no arbitrary external links or markup. Optional video_id must be an owned public eligible video. Content remains private until delivered.', $owned, AnnouncementController::rules(), ['title' => 'New release', 'body' => 'My latest original song'], '201');
        $add('AnnouncementController@show', 'Announcement owner detail', 'Owner-only draft or job status and aggregate recipient estimate/delivery counts. No contact list. Accepted content is immutable.', $owned);
        $add('AnnouncementController@update', 'Edit an announcement draft', 'Pro owner only, current integer version required. Accepted content and stale versions return 409. All content restrictions are rechecked.', $owned, AnnouncementController::rules(true), ['version' => 1, 'title' => 'Updated title']);
        $add('AnnouncementController@destroy', 'Delete an announcement draft', 'Owner-only, also available after downgrade. Deletes drafts only; accepted jobs return 409 and remain reportable.', null, status: '204');
        $add('AnnouncementController@send', 'Accept an announcement broadcast', 'Requires current version and Idempotency-Key header (8–200 visible ASCII characters). Freeze eligible audience and reserve quota atomically. Four accepted broadcasts per verified Pro paid period, at most 1000 recipients each; oversized audiences return 422 without quota use. Replay returns the original 202 result; conflicting key returns 409. Delivery rechecks consent revisions, blocks, accounts, content and the paid period. No retry consumes quota.', ['id' => 1, 'status' => 'queued', 'version' => 2, 'recipient_count' => 1], ['version' => ['required', 'integer', 'min:1']], ['version' => 1], '202');
        $add('AnnouncementController@inbox', 'Your delivered announcements', 'Actual delivered recipients only. Applies current artist/content/account/block visibility. Stable creation cursor, default 20/max 50. Does not mark anything read.', $received, cursor: true);
        $add('AnnouncementController@received', 'Read delivered announcement content', 'Only an actual recipient may retrieve it; blocked or moderated content returns 404. GET has no read-state side effect.', $received);
        $add('AnnouncementController@read', 'Mark announcement read', 'Idempotently marks your delivery and its linked notification read together. Existing notification read/read-all APIs synchronize the delivery too.', [...$received, 'read_at' => $date]);
        $add('NotificationController@unreadCount', 'Notification unread total', 'Current stored unread notification total for this account, independent of page/filter. Message unread counts remain separately available from the conversation inbox.', ['unread_count' => 1]);
        $add('RealtimeController@session', 'Register this login for Relay', 'Idempotent per current JWT record. Opaque private session channel and public connection settings only. Never exposes the secret. Logout/refresh deletes the old channel; expiry/suspension/deletion suppress publishing. Missing configuration returns 503; REST remains available.', ['key' => 'public-key', 'host' => 'ws.example.test', 'port' => 443, 'tls' => true, 'auth_url' => '/api/v1/broadcasting/auth', 'channel' => 'private-session.<opaque-key>', 'expires_at' => $date]);
        $add('RealtimeController@authorize', 'Authorize a Pusher private channel', 'Bearer token must match the exact registered login channel. Valid socket_id required. Unknown, foreign, retired and presence channels deny. Returns the raw Pusher auth object without a data wrapper or double encoding.', ['auth' => 'public-key:signature'], ['socket_id' => ['required', 'string', 'max:100'], 'channel_name' => ['required', 'string', 'max:200']], ['socket_id' => '123.456', 'channel_name' => 'private-session.<opaque-key>']);

        return $result;
    }
}
