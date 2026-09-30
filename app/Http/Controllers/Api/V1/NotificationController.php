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
        $notifications = Notification::where('user_id', $request->user('id'))
            ->latest()
            ->paginate(max(1, min(100, $request->integer('limit', 20))));

        return NotificationResource::collection($notifications);
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
