<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Spark\Http\{Request, Response};

class ReportController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'playlist_id' => ['nullable', 'integer', 'min:1'],
            'message_id' => ['prohibited'],
            'announcement_id' => ['prohibited'],
            'boost_id' => ['prohibited'],
            'audio_id' => ['nullable', 'integer', 'min:1'],
            'comment_id' => ['nullable', 'integer', 'min:1'],
            'video_id' => ['nullable', 'integer', 'min:1'],
            'reported_user_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        $report = \App\Services\Moderation::report($request->user(), $data->all());

        return json([
            'data' => ['id' => $report->id, 'status' => $report->status]
        ], 201);
    }
}
