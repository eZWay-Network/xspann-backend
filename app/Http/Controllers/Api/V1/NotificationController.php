<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Spark\Http\{Request, Resources\JsonResource, Response};

class NotificationController extends Controller
{
    public function index(Request $request): JsonResource
    {
        $request->validate(['unread' => ['sometimes', 'boolean']]);

        $unreadCount = Notification::where('user_id', $request->user('id'))
            ->whereNull('read_at')
            ->count();

        $notifications = Notification::where('user_id', $request->user('id'))
            ->when(
                $request->boolean('unread'),
                fn($query) => $query->whereNull('read_at')
            )
            ->latest()
            ->orderBy('id', 'DESC')
            ->paginate(max(1, min(100, $request->integer('limit', 20))));

        return NotificationResource::collection($notifications)
            ->additional(['meta' => ['unread_count' => $unreadCount]]);
    }

    public function read(Request $request, Notification $notification): JsonResource
    {
        abort_unless($notification->user_id === $request->user('id'), 404, 'Notification not found.');

        if (!$notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return NotificationResource::make($notification);
    }

    public function readAll(Request $request): Response
    {
        Notification::where('user_id', $request->user('id'))
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return json(['data' => ['message' => 'Notifications marked as read.']]);
    }
}
