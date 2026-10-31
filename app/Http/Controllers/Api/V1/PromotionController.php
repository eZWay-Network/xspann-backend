<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Models\Artist;
use App\Services\{Artists, Communications};
use App\Services\Billing\Billing;
use App\Services\Promotions\{Availability, Boosts, Credits, Delivery, Placements, Promotion, Reports};
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

class PromotionController extends Controller
{
    public static function boostRules(bool $edit = false): array
    {
        return [
            'video_id' => ['required', 'integer', 'min:1'], 'offer_id' => ['required', 'string', 'max:80'],
            'starts_at' => ['required', 'string', 'max:40'], 'rights_confirmed' => ['required', 'boolean'],
            'rights_version' => ['required', 'string', 'max:80'],
            ...($edit ? ['version' => ['required', 'integer', 'min:1']] : []),
        ];
    }

    public function offers(): Response
    {
        return $this->privateJson(['data' => [Availability::offer()]]);
    }

    public function placements(Request $request): Response
    {
        $request->validate(['surface' => ['required', 'string', 'in:' . config('promotions.surface')]]);

        return $this->privateJson(['data' => Delivery::placements($request->user()), 'meta' => ['capacity' => (int) config('promotions.capacity')]]);
    }

    public function event(Request $request): Response
    {
        $input = Communications::input($request, ['token' => ['required', 'string', 'max:4096'],
            'event_id' => ['required', 'string', 'max:36'], 'type' => ['required', 'string', 'in:render,click']]);

        return $this->privateJson(['data' => Delivery::event($input, $request->user())]);
    }

    public function featured(Request $request): Response
    {
        $query = Promotion::table('placements')->where('type', 'feature')->whereIn('status', ['scheduled', 'active'])
            ->where('starts_at', '<=', Billing::timestamp())->where('ends_at', '>', Billing::timestamp())
            ->whereNotExists(\App\Services\Promotions\Invalidations::placementQuery())
            ->whereIn('artist_id', Artist::visibleTo($request->user())->where('listed', true)->select('artists.id'));

        return $this->privateJson(Communications::page($query, $request, fn($row) => [
            'id' => (int) $row->id, 'artist' => $row->sponsor, 'disclosure' => 'Complimentary pilot feature',
            'starts_at' => Billing::date($row->starts_at), 'ends_at' => Billing::date($row->ends_at),
        ], function ($rows): array {
            $artists = $rows ? Artist::whereIn('id', array_column($rows, 'artist_id'))->get()->keyBy('id') : [];
            foreach ($rows as $row) {
                $artist = $artists[$row->artist_id];
                $row->sponsor = ['id' => $artist->id, 'name' => $artist->stage_name, 'slug' => $artist->slug];
            }

            return $rows;
        }));
    }

    public function balance(Request $request): Response
    {
        Artists::owned($request->user());

        return $this->privateJson(['data' => Credits::balance($request->user('id'))]);
    }

    public function ledger(Request $request): Response
    {
        Artists::owned($request->user());
        Promotion::transaction(fn() => Credits::sync($request->user('id')));
        $query = DB::table('promotion_ledger')->whereIn('credit_id', Promotion::table('credits')->select('id')->where('user_id', $request->user('id')));

        return $this->privateJson(Communications::page($query, $request, [PromotionResource::class, 'ledger']));
    }

    public function index(Request $request): Response
    {
        Artists::owned($request->user());

        return $this->privateJson(Communications::page(Promotion::table('boosts')->where('user_id', $request->user('id')), $request,
            [PromotionResource::class, 'boost'], [Reports::class, 'boosts']));
    }

    public function store(Request $request): Response
    {
        $input = Communications::input($request, self::boostRules(), booleans: ['rights_confirmed']);

        return $this->boostResponse(Boosts::create($request->user(), $input), Response::STATUS_CREATED);
    }

    public function show(Request $request, int $boost): Response
    {
        return $this->boostResponse(Boosts::find($boost, $request->user('id')));
    }

    public function update(Request $request, int $boost): Response
    {
        $input = Communications::input($request, self::boostRules(true), booleans: ['rights_confirmed']);

        return $this->boostResponse(Boosts::edit($request->user(), $boost, $input));
    }

    public function submit(Request $request, int $boost): Response
    {
        $input = Communications::input($request, ['version' => ['required', 'integer', 'min:1']]);

        return $this->privateJson(['data' => Boosts::submit($request->user(), $boost, (int) $input['version'], (string) $request->header('Idempotency-Key', ''))], Response::STATUS_ACCEPTED);
    }

    public function cancel(Request $request, int $boost): Response
    {
        $input = Communications::input($request, ['version' => ['required', 'integer', 'min:1']]);

        return $this->boostResponse(Boosts::cancel($request->user(), $boost, (int) $input['version']));
    }

    public function obligations(Request $request): Response
    {
        Artists::owned($request->user());

        return $this->privateJson(Communications::page(Promotion::table('placements')->where('user_id', $request->user('id')), $request,
            [PromotionResource::class, 'placement'], [Reports::class, 'placements']));
    }

    public function consent(Request $request, int $placement): Response
    {
        $input = Communications::input($request, ['version' => ['required', 'integer', 'min:1'], 'accepted' => ['required', 'boolean']], booleans: ['accepted']);

        return $this->privateJson(['data' => PromotionResource::placement(Reports::placements([
            Placements::consent($request->user(), $placement, (int) $input['version'], $input['accepted']),
        ])[0])]);
    }

    private function boostResponse(object $row, int $status = Response::STATUS_OK): Response
    {
        return $this->privateJson(['data' => PromotionResource::boost(Reports::boosts([$row])[0])], $status);
    }
}
