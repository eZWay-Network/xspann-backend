<?php

namespace App\Http\Controllers\Staff\Moderation;

use App\Http\Requests\Staff\{AppealDecisionRequest, ListFilters};
use App\Http\Resources\Staff\Records;
use App\Models\Report;
use App\Services\Moderation;
use App\Services\Staff\{Cases, Context};
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class AppealController
{
    public function index(Request $request): Response
    {
        $choices = ['status' => ['open', 'upheld', 'overturned']];
        $filters = ListFilters::read($request, $choices);
        $query = DB::table('moderation_appeals')->select(['id', 'decision_id', 'status', 'created_at']);
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if ($filters['q'] !== '') {
            $query->where('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0);
        }

        return Inertia::render('moderation/appeals/index', Records::listing($query, $filters,
            fn ($row) => Records::row($row, 'Appeal #' . $row->id, (string) route('staff.appeals.show', ['appeal' => $row->id]), $row->status, 'Decision #' . $row->decision_id),
            'staff.appeals.index', $choices) + ['page' => ['title' => 'Appeals', 'breadcrumbs' => []], 'description' => 'Independent review of challenged moderation decisions.']);
    }

    public function show(Request $request, int $appeal): Response
    {
        $context = Context::from($request);
        $row = DB::table('moderation_appeals')->where('id', $appeal)->firstOrFail();
        $decision = DB::table('moderation_decisions')->where('id', $row->decision_id)->firstOrFail();
        $report = Report::findOrFail($decision->report_id);
        $independent = Cases::independent($context, $report) && (int) $context->user->id !== (int) $decision->staff_id;

        return Inertia::render('moderation/appeals/show', [
            'page' => ['title' => 'Appeal #' . $appeal, 'breadcrumbs' => []],
            'record' => ['id' => $appeal, 'version' => (int) $row->version, 'status' => $row->status,
                'fields' => Records::fields($row, ['status', 'explanation', 'resolution', 'version', 'created_at'])],
            'decision' => ['action' => $decision->action, 'reason' => $decision->reason],
            'report_url' => $context->can('reports.view') ? (string) route('staff.reports.show', ['report' => $report->id]) : null,
            'decision_url' => $independent && $row->status === 'open' && $context->can('appeals.decide') ? (string) route('staff.appeals.decision', ['appeal' => $appeal]) : null,
            'conflict' => $independent ? null : 'An independent reviewer other than the original decision maker must handle this appeal.',
        ]);
    }

    public function decision(AppealDecisionRequest $request, int $appeal): Response
    {
        $input = $request->validated()->toArray();
        $input['version'] = (int) $input['version'];
        Moderation::decideAppeal($appeal, Context::from($request)->user, $input);
        Inertia::flash('success', 'Appeal decision recorded.');

        return Inertia::redirect((string) route('staff.appeals.show', ['appeal' => $appeal]), 303);
    }
}
