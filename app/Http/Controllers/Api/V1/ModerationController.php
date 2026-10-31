<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModerationResource;
use App\Models\Report;
use App\Services\{AuditTrail, Moderation};
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

class ModerationController extends Controller
{
    public function reports(Request $request): Response
    {
        $input = $request->validate([
            'status' => ['sometimes', ['in' => ['open', 'resolved']]],
            'type' => ['sometimes', ['in' => array_values(Moderation::TARGETS)]],
        ]);

        $query = DB::table('reports')->select(['id', 'target_type', 'target_id', 'reason', 'status', 'version', 'created_at']);

        if ($input->has('status')) {
            $query->where('status', $input->status);
        }
        if ($input->has('type')) {
            $query->where('target_type', $input->type);
        }

        AuditTrail::record('moderation.queue_viewed', [], $request->user('id'), 'staff');

        return ModerationResource::collection($query->orderBy('id')->paginate(max(1, min(100, $request->integer('limit', 20)))))->response();
    }

    public function report(Request $request, Report $report): Response
    {
        abort_if(in_array($request->user('id'), [$report->user_id, $report->subject_user_id], true), 403, 'An independent staff member must review this evidence.');

        AuditTrail::record('moderation.evidence_viewed', ['report_id' => $report->id], $request->user('id'), 'staff');

        return $this->privateJson([
            'data' => [
                'id' => $report->id,
                'target_type' => $report->target_type,
                'target_id' => $report->target_id,
                'reason' => $report->reason,
                'details' => $report->details,
                'status' => $report->status,
                'version' => $report->version,
                'evidence' => json_decode($report->evidence, true, flags: JSON_THROW_ON_ERROR),
                'decisions' => ModerationResource::collection(query('moderation_decisions')
                    ->select(['id', 'staff_id', 'action', 'reason', 'active', 'created_at'])->where('report_id', $report->id)->orderBy('id')->get()),
            ]
        ]);
    }

    public function decide(Request $request, Report $report): Response
    {
        $input = $request->validate([
            'action' => ['required', ['in' => ['dismiss', 'restrict', 'restore']]],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        return json(['data' => Moderation::decide($report->id, $request->user(), [...$input, 'version' => (int) $input->version])]);
    }

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

    public function appeals(Request $request): Response
    {
        $input = $request->validate(['status' => ['sometimes', ['in' => ['open', 'upheld', 'overturned']]]]);
        AuditTrail::record('moderation.appeals_viewed', [], $request->user('id'), 'staff');

        return ModerationResource::collection(query('moderation_appeals')
            ->select(['id', 'decision_id', 'user_id', 'reviewer_id', 'explanation', 'status', 'resolution', 'version', 'created_at', 'updated_at'])
            ->selectSub(query('moderation_decisions')->select('report_id')->whereColumn('moderation_decisions.id', 'moderation_appeals.decision_id')->limit(1), 'report_id')
            ->where('status', $input->status ?? 'open')->orderBy('id')->paginate(max(1, min(100, $request->integer('limit', 20)))))->response();
    }

    public function decideAppeal(Request $request, int $appeal): Response
    {
        $input = $request->validate([
            'outcome' => ['required', ['in' => ['uphold', 'overturn']]],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        return json(['data' => Moderation::decideAppeal($appeal, $request->user(), [...$input, 'version' => (int) $input->version])]);
    }
}
