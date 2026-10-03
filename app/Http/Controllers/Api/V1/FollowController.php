<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Follow;
use App\Models\User;
use App\Services\SocialNotifications;
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

class FollowController extends Controller
{
    public function store(Request $request, User $user, SocialNotifications $notifications): Response
    {
        abort_unless(
            User::visibleTo($request->user())->whereKey($user->id)->exists(),
            404,
            'User not found.'
        );

        abort_if($request->user()->is($user), 422, 'Users cannot follow themselves.');

        DB::transaction(function () use ($request, $user, $notifications): void {
            // Serialize follow creation on both SQLite and MySQL before checking existence.
            User::increment('id', 0, where: ['id' => $user->id]);

            $follow = Follow::firstOrCreate([
                'follower_id' => $request->user('id'),
                'following_id' => $user->id,
            ]);

            if ($follow->wasCreated()) {
                $notifications->followed($request->user(), $user);
            }
        });

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
        DB::transaction(function () use ($request, $user): void {
            User::increment('id', 0, where: ['id' => $user->id]);
            $request->user()->following()->detach($user->id);
        });

        return json([
            'data' => [
                'following' => false,
                'followers_count' => $user->followers()->count(),
                'following_count' => $request->user()->following()->count(),
            ],
        ]);
    }
}
