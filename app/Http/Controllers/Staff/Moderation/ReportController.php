<?php

namespace App\Http\Controllers\Staff\Moderation;

use App\Http\Requests\Staff\{AssignmentRequest, CaseRequest, ListFilters, NoteRequest, ReportDecisionRequest};
use App\Http\Resources\Staff\Records;
use App\Models\Report;
use App\Services\{AuditTrail, Moderation};
use App\Services\Staff\{Cases, Context, Permissions, Sessions};
use Inertia\Facades\Inertia;
use Spark\Facades\{DB, Hash};
use Spark\Http\{Request, Response};

final class ReportController
{
    public function index(Request $request): Response
    {
        return $this->queue($request);
    }

    public function rights(Request $request): Response
    {
        return $this->queue($request, true);
    }

    private function queue(Request $request, bool $rights = false): Response
    {
        $choices = ['status' => ['open', 'resolved'], 'type' => array_values(Moderation::TARGETS),
            'priority' => ['low', 'normal', 'high', 'urgent'], 'tag' => $rights ? ['rights'] : ['rights', 'safety', 'spam', 'other']];
        $filters = ListFilters::read($request, $choices + ['assignee_id' => []]);
        if ($rights) {
            $filters['tag'] = 'rights';
        }
        $query = DB::table('reports')->select(['id', 'target_type', 'target_id', 'status', 'version', 'source', 'created_at']);
        foreach (['assignee_id', 'priority'] as $column) {
            $query->selectSub(DB::table('report_workflows')->select($column)->whereColumn('report_id', 'reports.id')->limit(1), $column);
        }
        foreach (['status' => 'status', 'type' => 'target_type'] as $key => $column) {
            if (!empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        if ($filters['q'] !== '') {
            $query->where('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0);
        }
        foreach (['priority', 'tag', 'assignee_id'] as $key) {
            if ($key === 'priority' && $filters[$key] === 'normal') {
                $query->where(fn ($query) => $query
                    ->whereIn('id', DB::table('report_workflows')->select('report_id')->where('priority', 'normal'))
                    ->orWhereNotIn('id', DB::table('report_workflows')->select('report_id')));
            } elseif (!empty($filters[$key])) {
                $query->whereIn('id', DB::table('report_workflows')->select('report_id')->where($key, $filters[$key]));
            }
        }
        AuditTrail::record('moderation.queue_viewed', [], Context::from($request)->user->id, 'staff');
        $data = Records::listing($query, $filters, fn ($row) => Records::row($row,
            ucfirst($row->target_type) . ' #' . $row->target_id, (string) route('staff.reports.show', ['report' => $row->id]),
            $row->status, 'Case #' . $row->id . ' · ' . $row->source . ' · ' . ($row->priority ?? 'normal')
                . ' · ' . ($row->assignee_id ? 'Reviewer #' . $row->assignee_id : 'Unassigned')), $rights ? 'staff.rights' : 'staff.reports.index',
                $rights ? array_diff_key($choices, ['tag' => true]) : $choices);

        return Inertia::render($rights ? 'moderation/rights' : 'moderation/reports/index', $data + [
            'page' => ['title' => $rights ? 'Rights review' : 'Reports', 'breadcrumbs' => []],
            'description' => $rights ? 'Cases tagged for rights review. Declarations are not verified ownership.' : 'Review reported accounts and content. Evidence opens separately.',
        ]);
    }

    public function show(Request $request, int $report): Response
    {
        $context = Context::from($request);
        $row = Report::findOrFail($report);
        $independent = Cases::independent($context, $row);
        $workflow = DB::table('report_workflows')->where('report_id', $report)->first();
        $canWork = $context->can('reports.assign') || $context->can('reports.decide');
        $target = match ($row->target_type) {
            'user' => ['users.view', 'staff.users.show', 'user'],
            'video' => ['content.view', 'staff.videos.show', 'video'],
            'audio' => ['content.view', 'staff.audios.show', 'audio'],
            'playlist' => ['content.view', 'staff.playlists.show', 'playlist'],
            'boost' => ['boosts.view', 'staff.boosts.show', 'boost'],
            default => null,
        };
        $reviewers = [];
        if ($context->can('reports.assign')) {
            foreach (DB::table('staff_accounts')->join('users', 'users.id', '=', 'staff_accounts.user_id')
                ->where('staff_accounts.enabled', true)->where('users.status', 'active')->whereNotNull('users.email_verified_at')->whereNotNull('users.onboarded_at')
                ->select(['users.id', 'users.name', 'staff_accounts.role'])->orderBy('users.id')->limit(200)->get() as $member) {
                if (Permissions::allows($member->role, 'reports.decide') && !in_array((int) $member->id, array_map('intval', [$row->user_id, $row->subject_user_id, $row->initiator_id]), true)) {
                    $reviewers[] = ['value' => (string) $member->id, 'label' => $member->name . ' #' . $member->id];
                }
            }
        }
        $decisions = DB::table('moderation_decisions')->where('report_id', $report)->orderBy('id', 'desc')->limit(50)->get();
        $canDecide = $row->status === 'open' || DB::table('moderation_decisions')->where('report_id', $report)
            ->where('action', 'restrict')->where('active', true)->exists();
        $notes = $canWork ? DB::table('report_notes')->where('report_id', $report)->orderBy('id', 'desc')->limit(50)->all() : [];

        return Inertia::render('moderation/reports/show', [
            'page' => ['title' => 'Case #' . $report, 'breadcrumbs' => []],
            'target_url' => $target && $context->can($target[0]) ? (string) route($target[1], [$target[2] => $row->target_id]) : null,
            'record' => ['id' => $report, 'version' => (int) $row->version, 'status' => $row->status,
                'fields' => Records::fields((object) ['target_type' => $row->target_type, 'target_id' => $row->target_id, 'source' => $row->source, 'status' => $row->status, 'version' => $row->version, 'created_at' => (string) $row->created_at], ['target_type', 'target_id', 'source', 'status', 'version', 'created_at']),
                'reason' => $row->reason],
            'workflow' => ['version' => (int) ($workflow->version ?? 0), 'assignee_id' => $workflow?->assignee_id ? (string) $workflow->assignee_id : '',
                'priority' => $workflow->priority ?? 'normal', 'tag' => $workflow->tag ?? ''],
            'reviewers' => $reviewers,
            'notes' => array_map(fn ($note) => ['id' => (int) $note->id, 'actor_id' => $note->actor_id, 'body' => Hash::decrypt($note->body), 'created_at' => Sessions::iso($note->created_at)], $notes),
            'decisions' => array_map(fn ($decision) => ['id' => (int) $decision->id, 'action' => $decision->action, 'reason' => $decision->reason,
                'active' => (bool) $decision->active, 'created_at' => Records::date($decision->created_at)], $decisions->all()),
            'conflict' => $independent ? null : 'An independent staff member must review this case.',
            'actions' => [
                'evidence' => $independent && $context->can('reports.evidence.view') ? (string) route('staff.reports.evidence', ['report' => $report]) : null,
                'decision' => $canDecide && $independent && $context->can('reports.decide') ? (string) route('staff.reports.decision', ['report' => $report]) : null,
                'assignment' => $context->can('reports.assign') ? (string) route('staff.reports.assignment', ['report' => $report]) : null,
                'note' => $canWork ? (string) route('staff.reports.notes', ['report' => $report]) : null,
            ],
        ]);
    }

    public function create(CaseRequest $request): Response
    {
        $report = Cases::create(Context::from($request), $request->validated()->toArray());

        return $this->redirect($report->id, 'Review case created. An independent reviewer will make the decision.');
    }

    public function evidence(Request $request, int $report): Response
    {
        return json(Cases::evidence(Context::from($request), $report));
    }

    public function media(Request $request, int $report, string $asset): Response
    {
        return Cases::media(Context::from($request), $report, $asset, $request);
    }

    public function assignment(AssignmentRequest $request, int $report): Response
    {
        Cases::assign(Context::from($request), $report, $request->validated()->toArray());

        return $this->redirect($report, 'Case assignment updated.');
    }

    public function note(NoteRequest $request, int $report): Response
    {
        Cases::note(Context::from($request), $report, $request->validated('body'));

        return $this->redirect($report, 'Internal note added.');
    }

    public function decision(ReportDecisionRequest $request, int $report): Response
    {
        $input = $request->validated()->toArray();
        $input['version'] = (int) $input['version'];
        Moderation::decide($report, Context::from($request)->user, $input);

        return $this->redirect($report, 'Moderation decision recorded.');
    }

    private function redirect(int $report, string $message): Response
    {
        Inertia::flash('success', $message);

        return Inertia::redirect((string) route('staff.reports.show', ['report' => $report]), 303);
    }
}
