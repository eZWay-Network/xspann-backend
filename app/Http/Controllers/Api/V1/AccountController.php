<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\{AccountSecurity, PolicyEnrollment};
use Spark\Facades\DB;
use Spark\Http\{Request, Response};

class AccountController extends Controller
{
    public function policies(): Response
    {
        return json([
            'data' => [
                'minimum_age' => config('policies.minimum_age'),
                'policies' => config('policies.documents'),
                'rights_version' => config('policies.rights_version'),
                'rights_declaration' => 'I own or have permission for the video, audio, music and people shown, including any reuse I enable. I understand that selecting audio does not establish copyright clearance.',
            ]
        ]);
    }

    public function onboarding(Request $request): Response
    {
        $input = $request->validate([
            'onboarding_token' => ['required', 'string', 'max:128'],
            'date_of_birth' => ['required', 'string', 'max:10'],
            'accepted_policies' => ['required', 'array'],
        ]);

        PolicyEnrollment::validate($input->all());

        $payload = DB::transaction(function () use ($input): array {
            $userId = AccountSecurity::consume($input->onboarding_token, 'onboarding');
            $user = User::findOrFail($userId);

            abort_unless($user->status === 'active' && $user->hasVerifiedEmail() && !$user->onboarded_at, 403, 'Enrollment is unavailable.');

            PolicyEnrollment::complete($user, $input->all(), 'social_onboarding');

            DB::table('account_action_tokens')->where('user_id', $userId)->where('purpose', 'onboarding')->delete();

            return app(AuthController::class)->payload($user->refresh());
        });

        return json(['data' => $payload])->setHeader('Cache-Control', 'no-store');
    }

    public function acceptPolicies(Request $request): Response
    {
        $input = $request->validate(['accepted_policies' => ['required', 'array']]);
        DB::transaction(fn() => PolicyEnrollment::accept($request->user(), $input->accepted_policies));

        return json(['data' => ['message' => 'Policy acceptance recorded.']]);
    }

    public function reauthenticate(Request $request): Response
    {
        $input = $request->validate([
            'purpose' => ['required', ['in' => ['delete_account', 'export_data', 'staff']]],
            'password' => ['nullable', 'string', 'max:1024'],
            'google_token' => ['nullable', 'string', 'max:8192'],
            'mfa_code' => ['nullable', 'string', 'max:6'],
        ]);

        return json(['data' => AccountSecurity::reauthenticate($request->user(), $input->all())])->setHeader('Cache-Control', 'no-store');
    }
}
