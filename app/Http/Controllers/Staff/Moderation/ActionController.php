<?php

namespace App\Http\Controllers\Staff\Moderation;

use App\Http\Requests\Staff\ModerationActionRequest;
use App\Services\{AuditTrail, Moderation, ReleaseMedia};
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};

final class ActionController
{
    public function user(ModerationActionRequest $request, int $user): Response
    {
        return $this->apply($request, 'user', $user);
    }

    public function content(ModerationActionRequest $request, string $type, int $target): Response
    {
        abort_unless(in_array($type, ['video', 'audio', 'comment', 'playlist'], true), 404);
        return $this->apply($request, $type, $target);
    }

    private function apply(ModerationActionRequest $request, string $type, int $id): Response
    {
        Moderation::direct(Context::from($request), $type, $id, $request->validated()->toArray());
        Inertia::flash('success', 'Action recorded. The account or content is now updated.');
        $route = match ($type) {
            'user' => 'staff.users.show', 'video' => 'staff.videos.show', 'audio' => 'staff.audios.show',
            'comment' => 'staff.comments.show', 'playlist' => 'staff.playlists.show',
        };
        return Inertia::redirect((string) route($route, [$type => $id]), 303);
    }

    public function preview(Request $request, string $type, int $target): Response
    {
        abort_unless(in_array($type, ['video', 'audio'], true), 404);
        $context = Context::from($request);
        $record = Moderation::target($type, $target);
        AuditTrail::record('moderation.media_viewed', ['target_type' => $type, 'target_id' => $target,
            'subject_user_id' => Moderation::owner($type, $record)], $context->user->id, 'staff');
        return ReleaseMedia::deliver($record->storage_path, $request);
    }
}
