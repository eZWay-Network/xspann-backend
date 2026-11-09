<?php

namespace App\Services;

use App\Models\Notification;
use App\Services\Billing\Billing;
use Spark\Facades\DB;

class NotificationReads
{
    public static function unreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)->whereNull('read_at')->count();
    }

    public static function read(int $userId, ?int $id = null): void
    {
        Communications::transaction(function () use ($userId, $id): void {
            $query = Notification::where('user_id', $userId)->whereNull('read_at');
            if ($id !== null) {
                $query->whereKey($id);
            }
            // Link through indexed foreign keys, without parsing JSON or loading an entire inbox.
            DB::table('announcement_deliveries')->where('user_id', $userId)->whereNull('read_at')
                ->whereIn('notification_id', (clone $query)->select('id'))->update(['read_at' => Billing::timestamp()]);
            if ($query->update(['read_at' => now()])) {
                Realtime::record($userId, 'notifications.updated', $userId, 0);
            }
        });
    }

    public static function announcement(int $userId, int $id): void
    {
        Communications::transaction(function () use ($userId, $id): void {
            Announcements::delivered($id, $userId);
            $delivery = DB::table('announcement_deliveries')->where('announcement_id', $id)->where('user_id', $userId)->first();
            DB::table('announcement_deliveries')->where('id', $delivery->id)->whereNull('read_at')->update(['read_at' => Billing::timestamp()]);
            if ($delivery->notification_id) {
                self::read($userId, $delivery->notification_id);
            }
        });
    }

    public static function conversation(int $userId, int $conversationId, int $messageId): void
    {
        // Historical notification data is JSON; bound the scan with a key cursor.
        $changed = 0;
        $lastId = 0;
        do {
            $rows = Notification::where('user_id', $userId)->where('type', 'direct_message')->whereNull('read_at')
                ->where('id', '>', $lastId)->orderBy('id')->limit(200)->get();
            $ids = [];
            foreach ($rows as $row) {
                $lastId = $row->id;
                if (($row->data['conversation_id'] ?? null) === $conversationId && ($row->data['message_id'] ?? PHP_INT_MAX) <= $messageId) {
                    $ids[] = $row->id;
                }
            }
            if ($ids) {
                $changed += Notification::where('user_id', $userId)
                    ->where('type', 'direct_message')
                    ->whereNull('read_at')
                    ->whereIn('id', $ids)
                    ->update(['read_at' => now()]);
            }
        } while (count($rows) === 200);

        if ($changed > 0) {
            Realtime::record($userId, 'notifications.updated', $userId, 0);
        }
    }
}
