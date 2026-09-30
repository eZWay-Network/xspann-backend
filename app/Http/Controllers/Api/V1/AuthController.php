<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\{LoginRequest, RegisterRequest, ProfileUpdateRequest};
use App\Models\User;
use App\Http\Resources\ProfileResource;
use App\Services\AccountNotifications;
use App\Services\StorageService;
use App\Services\Social\GoogleAuth;
use Spark\Carbon;
use Spark\Facades\Auth;
use Spark\Facades\DB;
use Spark\Facades\Hash;
use Spark\Facades\Lock;
use Spark\Foundation\Exceptions\ValidationException;
use Spark\Http\{Request, Resources\JsonResource, Response};

class AuthController extends Controller
{
    public function register(RegisterRequest $request, AccountNotifications $notifications): Response
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->validated());
            StorageService::validateOwner($user->avatar, $user->id, 'avatar', 'avatars');

            return $user;
        });

        $notifications->verify($user);

        return json([
            'data' => [
                'user' => ProfileResource::make(User::withApiData($user, true)->findOrFail($user->id)),
                'token' => null,
                'token_expires_at' => null,
                'message' => 'We have sent you an email to verify your account. Please check your inbox or spam folder.',
            ]
        ], 201);
    }

    public function login(LoginRequest $request): Response
    {
        $user = User::where('email', $request->validated()->email())->first();

        if (!$user || !$user->password || !Hash::password($request->validated('password'), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.']
            ]);
        }

        abort_unless($user->status === 'active', 403, 'This account is not active.');
        abort_unless($user->hasVerifiedEmail(), 403, 'Please verify your email address before logging in.');

        return json(['data' => $this->payload($user)]);
    }

    public function logout(): Response
    {
        Auth::revokeToken();

        return json(['data' => ['message' => 'Logged out']]);
    }

    public function me(): JsonResource
    {
        return ProfileResource::make(
            User::with('authIdentities')->withApiData(Auth::user(), true)->findOrFail(Auth::id())
        );
    }

    public function update(ProfileUpdateRequest $request): JsonResource
    {
        StorageService::validateOwner($request->validated('avatar'), $request->user('id'), 'avatar', 'avatars');

        $request->user()->update($request->validated());

        return ProfileResource::make(User::withApiData($request->user(), true)->findOrFail($request->user('id')));
    }

    public function forgotPassword(Request $request, AccountNotifications $notifications): Response
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $data->email())->first();

        if ($user) {
            $notifications->reset($user);
        }

        return json(['data' => ['message' => 'If that email exists, a password reset link has been sent.']]);
    }

    public function resetPassword(Request $request): Response
    {
        $input = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        Lock::withLock('accounts.reset.' . hash('sha256', $input->email()), function () use ($input) {
            DB::transaction(function () use ($input) {
                $reset = query('password_reset_tokens')
                    ->where('email', $input->email())
                    ->first();

                $user = User::where('email', $input->email())->first();
                if (
                    !$reset || !$user || !Hash::password($input->token, $reset->token)
                    || Carbon::parse($reset->created_at)->addMinutes(config('app.password_reset_expiration_minutes', 60))->isPast()
                ) {
                    abort(422, 'This password reset token is invalid.');
                }

                $user->update(['password' => $input->password(), 'remember_token' => null]);

                query('jwt_access_tokens')->where('user_id', $user->id)->delete();
                query('password_reset_tokens')->where('email', $user->email)->delete();
            });
        }, timeout: 10, waitTimeout: 5);

        return json(['data' => ['message' => 'Password reset successfully. Please log in again.']]);
    }

    public function changePassword(Request $request): Response
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!$request->user('password') || !Hash::password($data['current_password'], $request->user('password'))) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.']
            ]);
        }

        DB::transaction(function () use ($request) {
            $request->user()->update(['password' => $request->validated('password'), 'remember_token' => null]);

            query('jwt_access_tokens')
                ->where('user_id', $request->user('id'))
                ->where('token_hash', '!=', Auth::token())
                ->delete();
        });

        return json(['data' => ['message' => 'Password changed successfully.']]);
    }

    public function resendVerification(Request $request, AccountNotifications $notifications): Response
    {
        $input = $request->validate(['email' => ['required', 'email']]);
        $user = User::where('email', $input->email())
            ->where('status', 'active')
            ->first();

        if ($user && !$user->hasVerifiedEmail()) {
            $notifications->verify($user);
        }

        return json(['data' => ['message' => 'If verification is needed, an email has been sent.']]);
    }

    public function verifyEmail(User $user, string $hash, Request $request, AccountNotifications $notifications): Response
    {
        if (!$notifications->validVerification($request)) {
            abort(403, 'Invalid signature.');
        }

        if (!hash_equals(sha1($user->email), $hash)) {
            abort(403, 'Invalid signature.');
        }

        if (!$user->hasVerifiedEmail()) {
            $user->update(['email_verified_at' => now()]);
        }

        return view('verified', compact('user'));
    }

    public function refreshToken(Request $request): Response
    {
        $payload = DB::transaction(function () use ($request) {
            Auth::revokeToken();
            return $this->payload($request->user());
        });

        return json(['data' => $payload]);
    }

    public function social(Request $request, string $provider): Response
    {
        $service = match ($provider) {
            'google' => app(GoogleAuth::class),
            default => abort(404, 'This sign-in provider is not supported.'),
        };

        $request->validate(['token' => 'required|string|max:8192']);

        return json(['data' => $this->payload($service->user($request->validated('token')))]);
    }

    private function payload(User $user): array
    {
        $expires = time() + max(1, config('app.api_token_expiration_minutes')) * 60;

        return [
            'user' => ProfileResource::make(User::withApiData($user, true)->findOrFail($user->id)),
            'token' => Auth::createToken($user, ['exp' => $expires]),
            'token_expires_at' => Carbon::parse($expires)
        ];
    }
}
