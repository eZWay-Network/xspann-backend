<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\{Communications, Realtime};
use Spark\Http\{Request, Response};

class RealtimeController extends Controller
{
    public function session(Request $request): Response
    {
        Communications::input($request, []);

        return $this->privateJson(['data' => Realtime::register($request->user('id'))]);
    }

    public function authorize(Request $request): Response
    {
        $input = Communications::input($request, [
            'socket_id' => ['required', 'string', 'max:100'],
            'channel_name' => ['required', 'string', 'max:200'],
        ]);

        return response(Realtime::authorize($request->user('id'), $input['channel_name'], $input['socket_id']), Response::STATUS_OK, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
