<?php

namespace App\Http\Controllers\Staff\Promotions;

use App\Http\Requests\Staff\{ListFilters, ObservationRequest};
use App\Http\Resources\Staff\Records;
use App\Services\Promotions\{Availability, Promotion};
use App\Services\Staff\Context;
use Inertia\Facades\Inertia;
use Spark\Http\{Request, Response};

final class SurfaceController
{
    public function index(Request $request): Response
    {
        $filters = ListFilters::read($request);
        $query = Promotion::table('availability');
        if ($filters['q'] !== '') {
            $query->where('reference', $filters['q']);
        }

        return Inertia::render('promotions/surface', Records::listing($query, $filters,
            fn ($row) => Records::row($row, $row->reference, null, $row->available ? 'available' : 'unavailable', $row->note, 'UTC') + [
                'starts_at' => Records::date($row->starts_at, 'UTC'),
                'ends_at' => Records::date($row->ends_at, 'UTC'),
            ],
            'staff.surface', [], true) + [
                'page' => ['title' => 'Promotion surface', 'breadcrumbs' => []],
                'description' => 'Actual observations and incident windows. Visiting this page does not attest availability.',
                'offer' => Availability::offer(),
                'lease_seconds' => (int) config('promotions.availability_lease_seconds'),
                'observation_url' => Context::from($request)->can('promotion.surface.record') ? (string) route('staff.surface.observe') : null,
            ]);
    }

    public function observe(ObservationRequest $request): Response
    {
        Availability::record($request->validated()->toArray(), Context::from($request)->user->id);
        Inertia::flash('success', 'Surface observation recorded.');

        return Inertia::redirect((string) route('staff.surface'), 303);
    }
}
