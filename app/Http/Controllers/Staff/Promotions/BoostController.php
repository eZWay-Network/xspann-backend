<?php

namespace App\Http\Controllers\Staff\Promotions;

use App\Http\Requests\Staff\{BoostDecisionRequest, BoostRemedyRequest, ListFilters};
use App\Http\Resources\PromotionResource;
use App\Http\Resources\Staff\Records;
use App\Services\Promotions\{Boosts, Credits, Promotion, Reports};
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

final class BoostController
{
    public function index(Request $request): Response
    {
        $choices = ['status' => ['draft', 'pending_review', 'scheduled', 'active', 'completed', 'rejected', 'cancelled', 'interrupted']];
        $filters = ListFilters::read($request, $choices + ['artist_id' => []]);
        $query = Promotion::table('boosts')->select(['id', 'artist_id', 'status', 'created_at']);
        foreach (['status', 'artist_id'] as $key) {
            if (!empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if ($filters['q'] !== '') {
            $query->where('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0);
        }

        return Inertia::render('promotions/boosts/index', Records::listing($query, $filters,
            fn ($row) => Records::row($row, 'Boost #' . $row->id, (string) route('staff.boosts.show', ['boost' => $row->id]), $row->status, 'Artist #' . $row->artist_id, 'UTC'),
            'staff.boosts.index', $choices) + ['page' => ['title' => 'Boosts', 'breadcrumbs' => []], 'description' => 'Review creative, delivery and credit-backed campaign remedies.']);
    }

    public function show(Request $request, int $boost): Response
    {
        $context = Context::from($request);
        $row = Reports::boosts([Boosts::find($boost)])[0];
        $independent = (int) $row->user_id !== (int) $context->user->id;
        $placement = Promotion::table('placements')->where('boost_id', $boost)->first();
        $creative = $row->artist_id && $row->video_id ? Promotion::creative($row->artist_id, $row->video_id) : null;
        $actions = [];
        foreach (['approve', 'reject', 'restrict', 'hold', 'release_hold'] as $action) {
            $ability = in_array($action, ['hold', 'release_hold'], true) ? 'boosts.hold' : 'boosts.review';
            $eligible = match ($action) {
                'approve' => $row->status === 'pending_review' && $row->starts_at > \App\Services\Billing\Billing::timestamp(),
                'reject' => in_array($row->status, ['pending_review', 'scheduled'], true) && !$row->activated_at,
                'release_hold' => (bool) $row->staff_hold,
                'hold' => !$row->staff_hold && in_array($row->status, ['pending_review', 'scheduled', 'active', 'interrupted'], true),
                default => in_array($row->status, ['pending_review', 'scheduled', 'active', 'interrupted'], true),
            };
            if ($independent && $eligible && $context->can($ability)) {
                $actions[] = $action;
            }
        }
        $report = $row->report;
        $preview = max(0, min($report['charged_cents'], Credits::unusedTarget($row, $report['delivered_seconds'])) - $report['returned_cents']);
        $remedies = [];
        if ($independent && $context->can('boosts.remedy') && $row->status === 'interrupted' && $row->activated_at && !Credits::heldForBoost($row)) {
            $remedies[] = 'credit';
            if ($placement && !$row->settled_at && $row->settle_after > \App\Services\Billing\Billing::timestamp() && $report['undelivered_seconds'] > 0) {
                $remedies[] = 'propose_extension';
                if ($placement->extension_consent === 'accepted' && $placement->extension_starts_at > \App\Services\Billing\Billing::timestamp()
                    && Promotion::seconds($placement->extension_ends_at) - Promotion::seconds($placement->extension_starts_at) === $report['undelivered_seconds']) {
                    $remedies[] = 'extend';
                }
            }
        }
        $filters = ListFilters::read($request);
        $delivery = DB::table('promotion_delivery')->where('boost_id', $boost)->select(['id', 'starts_at', 'ends_at'])
            ->orderBy('id', $filters['sort'] === 'oldest' ? 'asc' : 'desc')->cursorPaginate($filters['per_page']);

        return Inertia::render('promotions/boosts/show', [
            'page' => ['title' => 'Boost #' . $boost, 'breadcrumbs' => []],
            'boost' => PromotionResource::boost($row),
            'placement' => $placement ? PromotionResource::placement(Reports::placements([$placement])[0]) : null,
            'creative' => $creative ? ['caption' => $creative->caption, 'preview_url' => (string) route('staff.boosts.preview', ['boost' => $boost])] : null,
            'remedy_preview_cents' => $preview,
            'delivery' => [
                'rows' => array_map(fn ($interval) => ['id' => (int) $interval->id,
                    'starts_at' => Records::date($interval->starts_at, 'UTC'), 'ends_at' => Records::date($interval->ends_at, 'UTC')], $delivery->items()),
                'next_url' => $delivery->nextPageUrl(), 'previous_url' => $delivery->previousPageUrl(),
            ],
            'remedy_options' => $remedies,
            'decision_options' => $actions,
            'decision_url' => $actions ? (string) route('staff.boosts.decision', ['boost' => $boost]) : null,
            'remedy_url' => $remedies ? (string) route('staff.boosts.remedy', ['boost' => $boost]) : null,
            'conflict' => $independent ? null : 'An independent operator must handle your campaign.',
            'idempotency_key' => bin2hex(random_bytes(16)),
        ]);
    }

    public function preview(Request $request, int $boost): Response
    {
        $row = Boosts::find($boost);
        $creative = $row->artist_id && $row->video_id ? Promotion::creative($row->artist_id, $row->video_id) : null;
        abort_unless($creative !== null, 404, 'Public creative is unavailable.');

        return \App\Services\ReleaseMedia::deliver($creative->storage_path, $request);
    }

    public function decision(BoostDecisionRequest $request, int $boost): Response
    {
        $context = Context::from($request);
        $input = $request->validated()->toArray();
        $context->authorize(in_array($input['decision'], ['hold', 'release_hold'], true) ? 'boosts.hold' : 'boosts.review');
        Boosts::decide($context->user, $boost, $input);

        return $this->redirect($boost, 'Campaign decision recorded.');
    }

    public function remedy(BoostRemedyRequest $request, int $boost): Response
    {
        $input = $request->validated()->toArray();
        abort_if($input['action'] !== 'propose_extension' && isset($input['starts_at']), 422, 'Only a proposal accepts starts_at.');
        abort_if($input['action'] !== 'extend' && isset($input['placement_version']), 422, 'Only an extension consumes placement consent.');
        Boosts::remedy(Context::from($request)->user, $boost, $input, (string) $request->header('Idempotency-Key', ''));

        return $this->redirect($boost, $input['action'] === 'propose_extension' ? 'Extension proposed. Await owner consent to these exact dates.' : 'Campaign remedy recorded.');
    }

    private function redirect(int $boost, string $message): Response
    {
        Inertia::flash('success', $message);

        return Inertia::redirect((string) route('staff.boosts.show', ['boost' => $boost]), 303);
    }
}
