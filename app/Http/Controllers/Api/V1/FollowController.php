<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Spark\Http\{Request, Response};

class FollowController extends Controller
{
    public function store(Request $request, User $user): Response
    {
        abort_unless(
            User::visibleTo($request->user())->whereKey($user->id)->exists(),
            404,
            'User not found.'
        );

        abort_if($request->user()->is($user), 422, 'Users cannot follow themselves.');

        $request->user()->following()->syncWithoutDetaching([$user->id]);

        return json([
            'data' => [
                'following' => true,
                'followers_count' => $user->followers()->count(),
                'following_count' => $request->user()->following()->count(),
            ],
        ], 201);
    }

    public function destroy(Request $request, User $user): Response
    {
        $request->user()->following()->detach($user->id);

        return json([
            'data' => [
                'following' => false,
                'followers_count' => $user->followers()->count(),
                'following_count' => $request->user()->following()->count(),
            ],
        ]);
    }
}
