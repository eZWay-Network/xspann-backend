<?php

namespace App\Http\Controllers\Staff\Promotions;

use App\Http\Requests\Staff\{ListFilters, PlacementRequest, PlacementEditRequest, PlacementEvidenceRequest};
use App\Http\Resources\PromotionResource;
use App\Http\Resources\Staff\Records;
use App\Services\Promotions\{Placements, Promotion, Reports};
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};

final class PlacementController
{
    public function index(Request $request): Response
    {
        $choices = ['type' => ['feature', 'network_post', 'boost'], 'status' => ['planned', 'pending_review', 'scheduled', 'active', 'awaiting_evidence', 'completed', 'rejected', 'cancelled', 'interrupted']];
        $filters = ListFilters::read($request, $choices + ['artist_id' => [], 'month' => [], 'day' => [], 'view' => ['list', 'calendar']]);
        if (!empty($filters['month'])) {
            abort_unless(preg_match('/^20\d{2}-(0[1-9]|1[0-2])$/D', $filters['month']) === 1, 422, 'Choose a valid month.');
        }
        $query = Promotion::table('placements');
        foreach (['type', 'status', 'artist_id', 'month'] as $key) {
            if (!empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (!empty($filters['day'])) {
            $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $filters['day'], new \DateTimeZone('UTC'));
            abort_unless($day && $day->format('Y-m-d') === $filters['day'], 422, 'Choose a valid calendar date.');
            $query->where('starts_at', '<', $day->modify('+1 day')->format('Y-m-d H:i:s'))
                ->where('ends_at', '>', $day->format('Y-m-d H:i:s'));
        }
        if ($filters['q'] !== '') {
            $query->where('id', ctype_digit($filters['q']) ? (int) $filters['q'] : 0);
        }

        return Inertia::render('promotions/placements/index', Records::listing($query, $filters,
            fn ($row) => Records::row($row, Records::label($row->type) . ' #' . $row->id,
                (string) route('staff.placements.show', ['placement' => $row->id]), $row->status,
                'Artist #' . $row->artist_id . ' · ' . ($row->month ?? 'Campaign') . ' · ' . ($row->starts_at ?? 'Unscheduled'), 'UTC'),
            'staff.placements.index', array_diff_key($choices, ['type' => true])) + [
                'page' => ['title' => 'Placements', 'breadcrumbs' => []], 'description' => 'Paired monthly feature and network-post obligations. Scheduling is not delivery.',
                'create_url' => Context::from($request)->can('placements.manage') ? (string) route('staff.placements.create') : null,
            ]);
    }

    public function create(): Response
    {
        return Inertia::render('promotions/placements/create', [
            'page' => ['title' => 'Enroll monthly placements', 'breadcrumbs' => []],
            'store_url' => (string) route('staff.placements.store'),
            'network_accounts' => array_map(fn ($key) => ['value' => $key, 'label' => $key], array_keys(config('promotions.network_accounts', []))),
            'capacity' => (int) config('promotions.pilot_monthly_capacity'),
        ]);
    }

    public function show(Request $request, int $placement): Response
    {
        $context = Context::from($request);
        $row = Placements::find($placement);
        $independent = (int) $row->user_id !== (int) $context->user->id;
        $counterparts = $row->enrollment_id ? Promotion::table('placements')->where('enrollment_id', $row->enrollment_id)->orderBy('id')->all() : [];

        return Inertia::render('promotions/placements/show', [
            'page' => ['title' => Records::label($row->type) . ' #' . $placement, 'breadcrumbs' => []],
            'placement' => PromotionResource::placement(Reports::placements([$row])[0]),
            'counterparts' => array_map(fn ($item) => ['label' => Records::label($item->type) . ' · ' . $item->status,
                'url' => (string) route('staff.placements.show', ['placement' => $item->id])], $counterparts),
            'edit_url' => $independent && $row->type !== 'boost' && !in_array($row->status, ['completed', 'cancelled'], true)
                && $context->can('placements.manage') ? (string) route('staff.placements.update', ['placement' => $placement]) : null,
            'evidence_url' => $independent && $row->type !== 'boost' && in_array($row->status, ['scheduled', 'active', 'awaiting_evidence'], true)
                && $context->can('placements.evidence') ? (string) route('staff.placements.evidence', ['placement' => $placement]) : null,
            'conflict' => $independent ? null : 'An independent operator must handle your placements.',
        ]);
    }

    public function store(PlacementRequest $request): Response
    {
        $row = Placements::create(Context::from($request)->user, $request->validated()->toArray());

        return $this->redirect($row->id, 'Both monthly obligations created. Record delivery evidence separately.');
    }

    public function update(PlacementEditRequest $request, int $placement): Response
    {
        $input = $request->validated()->toArray();
        abort_if($input['action'] !== 'schedule' && (isset($input['starts_at']) || isset($input['ends_at'])), 422, 'Only scheduling accepts dates.');
        Placements::edit(Context::from($request)->user, $placement, $input);

        return $this->redirect($placement, 'Placement updated. Replacement dates require owner acceptance.');
    }

    public function evidence(PlacementEvidenceRequest $request, int $placement): Response
    {
        Placements::evidence(Context::from($request)->user, $placement, $request->validated()->toArray());

        return $this->redirect($placement, 'Delivery evidence recorded for this obligation.');
    }

    private function redirect(int $placement, string $message): Response
    {
        Inertia::flash('success', $message);

        return Inertia::redirect((string) route('staff.placements.show', ['placement' => $placement]), 303);
    }
}
