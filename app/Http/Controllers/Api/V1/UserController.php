<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Http\Resources\UserResource;
use App\Http\Resources\VideoResource;
use App\Models\User;
use App\Services\PaginatesApiResponses;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    use PaginatesApiResponses;

    public function suggestions(Request $request): JsonResponse
    {
        $viewer = $request->user();
        $followingIds = $viewer
            ? $viewer->following()->select('users.id')
            : null;

        $users = User::query()
            ->where('status', 'active')
            ->when($viewer, fn ($query) => $query->whereKeyNot($viewer->id))
            ->when($followingIds, fn ($query) => $query->whereNotIn('id', $followingIds))
            ->withCount('followers')
            ->orderByDesc('followers_count')
            ->latest()
            ->paginate($request->integer('limit', 18));

        return $this->paginated($users, fn ($user) => new UserResource($user, $viewer), $request);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        return response()->json([
            'data' => (new ProfileResource($user, $request->user()))->resolve($request),
        ]);
    }

    public function videos(Request $request, User $user): JsonResponse
    {
        $videos = $user->videos()
            ->published()
            ->visibleTo($request->user())
            ->withViewerState($request->user())
            ->with('user')
            ->latest()
            ->paginate($request->integer('limit', 10));

        return $this->paginated($videos, fn ($video) => new VideoResource($video, $request->user()), $request);
    }

    public function followers(Request $request, User $user): JsonResponse
    {
        $followers = $user->followers()->paginate($request->integer('limit', 20));

        return $this->paginated($followers, fn ($follower) => new UserResource($follower, $request->user()), $request);
    }

    public function following(Request $request, User $user): JsonResponse
    {
        $following = $user->following()->paginate($request->integer('limit', 20));

        return $this->paginated($following, fn ($followed) => new UserResource($followed, $request->user()), $request);
    }
}
