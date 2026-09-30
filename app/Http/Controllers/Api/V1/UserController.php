<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Http\Resources\{ProfileResource, UserResource, VideoResource};
use Spark\Http\{Request, Resources\JsonResource};

class UserController extends Controller
{
    public function suggestions(Request $request): JsonResource
    {
        $viewer = $request->user();

        $users = User::query()
            ->visibleTo($viewer)
            ->when(
                $viewer,
                fn($query) => $query
                    ->where('users.id', '!=', $viewer->id)
                    ->whereNotIn('users.id', $viewer->following()->select('users.id'))
            )
            ->withApiData($viewer)
            ->orderByRaw('followers_count DESC, users.created_at DESC')
            ->paginate(max(1, min(100, $request->integer('limit', 18))));

        return UserResource::collection($users);
    }

    public function show(Request $request, User $user): JsonResource
    {
        return ProfileResource::make(
            User::visibleTo($request->user())->withApiData($request->user(), true)->findOrFail($user->id)
        );
    }

    public function videos(Request $request, User $user): JsonResource
    {
        abort_unless(
            User::visibleTo($request->user())->whereKey($user->id)->exists(),
            404,
            'User not found.'
        );

        $videos = $user->videos()
            ->published()
            ->visibleTo($request->user())
            ->withApiData($request->user())
            ->latest()
            ->paginate(max(1, min(100, $request->integer('limit', 10))));

        return VideoResource::collection($videos);
    }

    public function followers(Request $request, User $user): JsonResource
    {
        abort_unless(
            User::visibleTo($request->user())->whereKey($user->id)->exists(),
            404,
            'User not found.'
        );

        $followers = $user->followers()
            ->visibleTo($request->user())
            ->withApiData($request->user())
            ->paginate(max(1, min(100, $request->integer('limit', 20))));

        return UserResource::collection($followers);
    }

    public function following(Request $request, User $user): JsonResource
    {
        abort_unless(
            User::visibleTo($request->user())->whereKey($user->id)->exists(),
            404,
            'User not found.'
        );

        $following = $user->following()
            ->visibleTo($request->user())
            ->withApiData($request->user())
            ->paginate(max(1, min(100, $request->integer('limit', 20))));

        return UserResource::collection($following);
    }
}
