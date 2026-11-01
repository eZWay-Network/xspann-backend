<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModerationResource;
use App\Models\Report;
use App\Services\Moderation;
use Spark\Http\{Request, Response};

class ModerationController extends Controller
{
    public function ownReport(Request $request, Report $report): Response
    {
        abort_unless($report->user_id === $request->user('id'), 404, 'Report not found.');

        return json(['data' => ['id' => $report->id, 'status' => $report->status, 'version' => $report->version]]);
    }

    public function ownDecisions(Request $request): Response
    {
        return ModerationResource::collection(query('moderation_decisions')->select(['id', 'action', 'reason', 'active', 'created_at'])
            ->where('subject_user_id', $request->user('id'))->orderBy('id', 'DESC')->paginate(max(1, min(100, $request->integer('limit', 20)))))->response();
    }

    public function appeal(Request $request): Response
    {
        $input = $request->validate([
            'decision_id' => ['required', 'integer', 'min:1'],
            'explanation' => ['required', 'string', 'min:3', 'max:4000'],
        ]);

        return json(['data' => Moderation::appeal($request->user(), (int) $input->decision_id, $input->explanation)], 201);
    }

    public function ownAppeals(Request $request): Response
    {
        return ModerationResource::collection(query('moderation_appeals')->select(['id', 'decision_id', 'explanation', 'status', 'resolution', 'version', 'created_at', 'updated_at'])
            ->where('user_id', $request->user('id'))->orderBy('id', 'DESC')->paginate(max(1, min(100, $request->integer('limit', 20)))))->response();
    }
}
