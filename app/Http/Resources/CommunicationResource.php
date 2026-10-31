<?php

namespace App\Http\Resources;

use App\Services\Billing\Billing;
use Spark\Facades\Hash;

/** Explicit representations; encrypted columns and internal consent snapshots never escape. */
class CommunicationResource
{
    public static function message(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'conversation_id' => (int) $row->conversation_id,
            'sender_user_id' => (int) $row->user_id,
            'client_message_id' => $row->client_message_id,
            'body' => $row->moderation_status === 'approved' ? Hash::decrypt($row->body) : null,
            'unavailable' => $row->moderation_status !== 'approved',
            'created_at' => Billing::date($row->created_at),
        ];
    }

    public static function conversation(object $row, int $viewer): array
    {
        $other = (int) ($row->low_user_id == $viewer ? $row->high_user_id : $row->low_user_id);

        return [
            'id' => (int) $row->id,
            'recipient_user_id' => $other,
            'version' => (int) $row->version,
            'can_send' => (bool) $row->can_send,
            'last_read_message_id' => (int) $row->last_read_message_id,
            'muted' => (bool) $row->muted,
            'unread_count' => (int) $row->unread_count,
            'last_message' => $row->last_message ? self::message($row->last_message) : null,
            'created_at' => Billing::date($row->created_at),
        ];
    }

    public static function announcement(object $row, bool $owner = false, ?object $delivery = null): array
    {
        $result = [
            'id' => (int) $row->id,
            'artist_id' => (int) $row->artist_id,
            'title' => Hash::decrypt($row->title),
            'body' => Hash::decrypt($row->body),
            'video_id' => $row->video_id ? (int) $row->video_id : null,
            'version' => (int) $row->version,
            'created_at' => Billing::date($row->created_at),
        ];
        if ($owner) {
            $result += [
                'status' => $row->status,
                'moderation_status' => $row->moderation_status,
                'recipient_count' => (int) $row->recipient_count,
                'recipient_estimate' => (int) $row->recipient_estimate,
                'delivered_count' => (int) $row->delivered_count,
                'suppressed_count' => (int) $row->suppressed_count,
                'accepted_at' => Billing::date($row->accepted_at),
            ];
        } else {
            $result += ['read_at' => Billing::date($delivery?->read_at), 'delivered_at' => Billing::date($delivery?->delivered_at)];
        }

        return $result;
    }
}
