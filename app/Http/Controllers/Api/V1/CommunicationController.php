<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Communications;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

class CommunicationController extends Controller
{
    public function preferences(Request $request): Response
    {
        return $this->privateJson(['data' => Communications::preferences($request->user('id'))]);
    }

    public function updatePreferences(Request $request): Response
    {
        $rules = array_fill_keys(array_keys(Communications::DEFAULTS), ['sometimes', 'boolean']);
        $input = Communications::input($request, $rules, array_keys($rules));

        return $this->privateJson(['data' => Communications::updatePreferences($request->user('id'), $input)]);
    }

    public function subscription(Request $request, int $artist): Response
    {
        abort_unless(DB::table('artists')->where('id', $artist)->exists(), 404, 'Artist not found.');

        return $this->privateJson(['data' => Communications::subscription($request->user('id'), $artist)]);
    }

    public function subscribe(Request $request, int $artist): Response
    {
        $input = Communications::input($request, [
            'subscribed' => ['required', 'boolean'],
            'consent_version' => ['sometimes', 'string', 'max:80'],
        ], ['subscribed']);

        return $this->privateJson(['data' => Communications::subscribe($request->user(), $artist, $input['subscribed'], $input['consent_version'] ?? null)]);
    }
}
