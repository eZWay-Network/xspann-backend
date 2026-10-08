<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\VideoFeed;
use Spark\Http\Request;
use Spark\Http\Resources\JsonResource;

class FeedController extends Controller
{
    public function index(Request $request, VideoFeed $feed): JsonResource
    {
        return $this->respond($request, $feed, false);
    }

    public function following(Request $request, VideoFeed $feed): JsonResource
    {
        return $this->respond($request, $feed, true);
    }

    private function respond(Request $request, VideoFeed $feed, bool $following): JsonResource
    {
        $input = $request->validate([
            'pagination' => ['sometimes', 'string', 'in:page,cursor'],
            'cursor' => ['sometimes', 'string', 'max:4096'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'limit' => ['sometimes', 'integer'],
        ]);

        $limit = max(1, min(100, (int) $input->get('limit', 10)));
        $cursor = $input->get('cursor');

        if ($input->get('pagination') === 'cursor' || $cursor !== null) {
            return $feed->cursor($request, $limit, $following, $cursor);
        }

        return $feed->page($request, $limit, $following);
    }
}
