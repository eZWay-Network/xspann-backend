<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\SocialNotifications;
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

        SocialNotifications::redact($notifications->items(), $request->user());

        return NotificationResource::collection($notifications)
            ->additional(['meta' => ['unread_count' => $unreadCount]]);
    }

    public function read(Request $request, Notification $notification): JsonResource
    {
        abort_unless($notification->user_id === $request->user('id'), 404, 'Notification not found.');

        \App\Services\NotificationReads::read($request->user('id'), $notification->id);
        $notification->refresh();

        SocialNotifications::redact([$notification], $request->user());

        return NotificationResource::make($notification);
    }

    public function readAll(Request $request): Response
    {
        \App\Services\NotificationReads::read($request->user('id'));

        return json(['data' => ['message' => 'Notifications marked as read.']]);
    }

    public function unreadCount(Request $request): Response
    {
        return json(['data' => ['unread_count' => Notification::where('user_id', $request->user('id'))->whereNull('read_at')->count()]]);
    }

    public function clearAll(Request $request): Response
    {
        \App\Services\Communications::transaction(function () use ($request): void {
            $deleted = Notification::where('user_id', $request->user('id'))->whereNotNull('read_at')->delete();
            if ($deleted) {
                \App\Services\Realtime::record($request->user('id'), 'notifications.updated', $request->user('id'), 0);
            }
        });

        return json(['data' => ['message' => 'Read notifications cleared.']]);
    }
}
