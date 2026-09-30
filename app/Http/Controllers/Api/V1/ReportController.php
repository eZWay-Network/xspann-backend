<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{Report, User};
use App\Services\VideoActions;
use Spark\Http\{Request, Response};

class ReportController extends Controller
{
    public function store(Request $request): Response
    {
        $data = $request->validate([
            'video_id' => ['nullable', 'integer', 'min:1'],
            'reported_user_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
            'details' => ['nullable', 'string', 'max:2000'],
        ]);

        abort_if(
            (bool) $data->video_id === (bool) $data->reported_user_id,
            422,
            'Select either a video or a user to report.'
        );

        if ($data->video_id) {
            VideoActions::visible((int) $data->video_id);
        } else {
            User::whereKey($data->reported_user_id)->firstOrFail();

            abort_if(
                (int) $data->reported_user_id === $request->user('id'),
                422,
                'Users cannot report themselves.'
            );
        }

        $report = Report::create([
            ...$data,
            'user_id' => $request->user('id'),
            'status' => 'open'
        ]);

        return json([
            'data' => ['id' => $report->id, 'status' => $report->status]
        ], 201);
    }
}
