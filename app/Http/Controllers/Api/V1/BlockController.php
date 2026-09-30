<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\{Block, Follow, User};
use Spark\Facades\DB;
use Spark\Http\{Request, Resources\JsonResource, Response};

class BlockController extends Controller
{
    public function index(Request $request): JsonResource
    {
        $users = User::whereIn(
            'users.id',
            Block::select('blocked_id')->where('blocker_id', $request->user('id'))
        )
            ->withApiData($request->user())
            ->latest()
            ->paginate(max(1, min(100, $request->integer('limit', 20))));

        return UserResource::collection($users);
    }

    public function store(Request $request, User $user): Response
    {
        abort_if($user->is($request->user()), 422, 'Users cannot block themselves.');

        DB::transaction(function () use ($request, $user) {
            Block::query()
                ->insertOrIgnore([
                    'blocker_id' => $request->user('id'),
                    'blocked_id' => $user->id,
                    'created_at' => now()
                ]);

            Follow::where(fn($query) => $query->where('follower_id', $request->user('id'))->where('following_id', $user->id))
                ->orWhere(fn($query) => $query->where('follower_id', $user->id)->where('following_id', $request->user('id')))
                ->delete();
        });

        return json(['data' => ['blocked' => true]], 201);
    }

    public function destroy(Request $request, User $user): Response
    {
        Block::where('blocker_id', $request->user('id'))
            ->where('blocked_id', $user->id)
            ->delete();

        return json(['data' => ['blocked' => false]]);
    }
}
