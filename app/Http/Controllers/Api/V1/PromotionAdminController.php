<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Services\Communications;
use App\Services\Promotions\{Availability, Boosts, Placements, Promotion, Reports};
use Spark\Http\{Request, Response};

class PromotionAdminController extends Controller
{
    public static function placementRules(): array
    {
        return ['artist_id' => ['required', 'integer', 'min:1'], 'month' => ['required', 'string', 'max:7'],
            'type' => ['required', 'string', 'in:feature,network_post'], 'network_account' => ['required', 'string', 'max:80'],
            'capacity_confirmed' => ['required', 'boolean'], 'starts_at' => ['sometimes', 'required', 'string', 'max:40'],
            'ends_at' => ['sometimes', 'required', 'string', 'max:40'], 'reason' => ['required', 'string', 'max:1000']];
    }

    public static function editRules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'action' => ['required', 'string', 'in:schedule,interrupt,cancel'],
            'starts_at' => ['sometimes', 'required', 'string', 'max:40'], 'ends_at' => ['sometimes', 'required', 'string', 'max:40'],
            'reason' => ['required', 'string', 'max:1000']];
    }

    public static function evidenceRules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'actual_starts_at' => ['required', 'string', 'max:40'],
            'actual_ends_at' => ['required', 'string', 'max:40'], 'proof' => ['required', 'string', 'max:2000'], 'note' => ['required', 'string', 'max:1000']];
    }

    public static function decisionRules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'decision' => ['required', 'string', 'in:approve,reject,restrict,hold,release_hold'],
            'reason' => ['required', 'string', 'max:1000']];
    }

    public static function remedyRules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'action' => ['required', 'string', 'in:credit,propose_extension,extend'],
            'starts_at' => ['sometimes', 'required', 'string', 'max:40'], 'placement_version' => ['sometimes', 'required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000']];
    }

    public static function observationRules(): array
    {
        return ['available' => ['required', 'boolean'], 'reference' => ['required', 'string', 'max:200'],
            'note' => ['required', 'string', 'max:1000'], 'starts_at' => ['sometimes', 'required', 'string', 'max:40'],
            'ends_at' => ['sometimes', 'required', 'string', 'max:40']];
    }

    public function index(Request $request): Response
    {
        $query = Promotion::table('boosts');
        $input = $request->validate(['status' => ['sometimes', 'required', 'string', 'in:draft,pending_review,scheduled,active,completed,rejected,cancelled,interrupted'],
            'artist_id' => ['sometimes', 'integer', 'min:1']]);
        foreach (['status', 'artist_id'] as $key) {
            if ($input->has($key)) {
                $query->where($key, $input[$key]);
            }
        }

        return $this->privateJson(Communications::page($query, $request, [PromotionResource::class, 'boost'], [Reports::class, 'boosts']));
    }

    public function show(int $boost): Response
    {
        return $this->privateJson(['data' => PromotionResource::boost(Reports::boosts([Boosts::find($boost)])[0])]);
    }

    public function decision(Request $request, int $boost): Response
    {
        $input = Communications::input($request, self::decisionRules(), text: ['reason']);

        return $this->privateJson(['data' => PromotionResource::boost(Reports::boosts([Boosts::decide($request->user(), $boost, $input)])[0])]);
    }

    public function remedy(Request $request, int $boost): Response
    {
        $input = Communications::input($request, self::remedyRules(), text: ['reason']);
        abort_if($input['action'] !== 'propose_extension' && isset($input['starts_at']), 422, 'Only a proposal accepts starts_at.');
        abort_if($input['action'] !== 'extend' && isset($input['placement_version']), 422, 'Only an extension consumes placement consent.');

        return $this->privateJson(['data' => Boosts::remedy($request->user(), $boost, $input, (string) $request->header('Idempotency-Key', ''))]);
    }

    public function placements(Request $request): Response
    {
        $query = Promotion::table('placements');
        $input = $request->validate(['month' => ['sometimes', 'string', 'max:7'], 'artist_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:planned,pending_review,scheduled,active,awaiting_evidence,completed,rejected,cancelled,interrupted']]);
        foreach (['month', 'artist_id', 'status'] as $key) {
            if ($input->has($key)) {
                $query->where($key, $input[$key]);
            }
        }

        return $this->privateJson(Communications::page($query, $request, [PromotionResource::class, 'placement'], [Reports::class, 'placements']));
    }

    public function storePlacement(Request $request): Response
    {
        $input = Communications::input($request, self::placementRules(), booleans: ['capacity_confirmed'], text: ['reason', 'network_account']);

        return $this->placementResponse(Placements::create($request->user(), $input), Response::STATUS_CREATED);
    }

    public function updatePlacement(Request $request, int $placement): Response
    {
        $input = Communications::input($request, self::editRules(), text: ['reason']);
        abort_if($input['action'] !== 'schedule' && (isset($input['starts_at']) || isset($input['ends_at'])), 422, 'Only scheduling accepts dates.');

        return $this->placementResponse(Placements::edit($request->user(), $placement, $input));
    }

    public function evidence(Request $request, int $placement): Response
    {
        $input = Communications::input($request, self::evidenceRules(), text: ['proof', 'note']);

        return $this->placementResponse(Placements::evidence($request->user(), $placement, $input));
    }

    public function surface(): Response
    {
        return $this->privateJson(['data' => ['offer' => Availability::offer(), 'availability_lease_seconds' => (int) config('promotions.availability_lease_seconds'),
            'pilot_monthly_capacity' => (int) config('promotions.pilot_monthly_capacity'), 'feature_capacity' => (int) config('promotions.feature_capacity'),
            'network_accounts' => config('promotions.network_accounts')]]);
    }

    public function observe(Request $request): Response
    {
        $input = Communications::input($request, self::observationRules(), booleans: ['available'], text: ['reference', 'note']);

        return $this->privateJson(['data' => PromotionResource::observation(Availability::record($input, $request->user('id')))]);
    }

    private function placementResponse(object $row, int $status = Response::STATUS_OK): Response
    {
        return $this->privateJson(['data' => PromotionResource::placement(Reports::placements([$row])[0])], $status);
    }
}
