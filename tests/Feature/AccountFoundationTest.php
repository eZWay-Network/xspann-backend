<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{AccountSecurity, Totp};
use Spark\Facades\Hash;
use Tests\TestCase;

class AccountFoundationTest extends TestCase
{
    public function testPublicProfilesNeverExposePrivateAccountFields(): void
    {
        $user = $this->makeUser();
        $public = $this->getJson('/api/v1/users/alice')->assertOk()->json('data');
        foreach (['email', 'email_verified_at', 'date_of_birth', 'social_identities', 'password'] as $field) {
            $this->assertFalse(array_key_exists($field, $public));
        }
        $this->asUser($user)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $user->email);
        $this->assertThrows(\Spark\Foundation\Exceptions\ValidationException::class,
            fn() => \App\Services\PolicyEnrollment::validate([...$this->enrollment(), 'date_of_birth' => "1990-\0-01"]));
    }

    public function testRegistrationRequiresAdultAgeAndCurrentPoliciesAndKeepsBirthDatePrivate(): void
    {
        $base = ['username' => 'newuser', 'email' => 'new@example.com', 'password' => 'password123', 'password_confirmation' => 'password123'];
        $this->postJson('/api/v1/auth/register', $base)->assertStatus(422)->assertJsonValidationErrors(['date_of_birth', 'accepted_policies']);
        $this->postJson('/api/v1/auth/register', [...$base, ...$this->enrollment(), 'date_of_birth' => now()->subYears(17)->format('Y-m-d')])->assertStatus(422);
        $this->postJson('/api/v1/auth/register', [...$base, ...$this->enrollment(), 'accepted_policies' => ['terms' => 'old']])->assertStatus(422);
        $response = $this->postJson('/api/v1/auth/register', [...$base, ...$this->enrollment()])->assertStatus(201);
        $this->assertFalse(array_key_exists('date_of_birth', $response->json('data.user')));
        $user = User::where('email', 'new@example.com')->first();
        $this->assertSame('1990-01-01', $user->date_of_birth);
        $stored = query('users')->where('id', $user->id)->first()->date_of_birth;
        $this->assertFalse(str_contains($stored, '1990-01-01'));
        $this->assertDatabaseCount('policy_acceptances', 3);
        $this->assertDatabaseCount('jwt_access_tokens', 0);
        $this->assertFalse(array_key_exists('date_of_birth', $user->toArray()));
    }

    public function testOnboardingProofIsRestrictedSingleUseAndDoesNotConsumeOnInvalidAge(): void
    {
        $user = $this->makeUser();
        $user->update(['onboarded_at' => null, 'date_of_birth' => null]);
        $token = AccountSecurity::issue($user->id, 'onboarding', 600);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->flushHeaders()->getJson('/api/v1/users/alice')->assertStatus(404);
        $this->postJson('/api/v1/auth/onboarding', [...$this->enrollment(), 'onboarding_token' => $token, 'date_of_birth' => '2020-01-01'])->assertStatus(422);
        $access = $this->postJson('/api/v1/auth/onboarding', [...$this->enrollment(), 'onboarding_token' => $token])->assertOk()->json('data.token');
        $this->assertTrue(is_string($access));
        $this->postJson('/api/v1/auth/onboarding', [...$this->enrollment(), 'onboarding_token' => $token])->assertStatus(422);
        $this->withToken($access)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function testPoliciesAreVersionedAndAcceptanceIsIdempotentAndSuspendedAccountsHaveRecoveryOnly(): void
    {
        $this->getJson('/api/v1/policies')->assertOk()->assertJsonPath('data.minimum_age', 18)->assertJsonPath('data.rights_version', config('policies.rights_version'));
        $user = $this->makeUser();
        $this->asUser($user);
        $this->postJson('/api/v1/me/policy-acceptances', $this->enrollment())->assertOk();
        $this->postJson('/api/v1/me/policy-acceptances', $this->enrollment())->assertOk();
        $this->assertDatabaseCount('policy_acceptances', 3);
        $this->postJson('/api/v1/me/policy-acceptances', ['accepted_policies' => ['marketing' => 'old']])->assertStatus(422);
        $user->update(['status' => 'suspended']);
        $this->getJson('/api/v1/auth/me')->assertStatus(403);
        $this->getJson('/api/v1/me/moderation-decisions')->assertOk();
        $this->postJson('/api/v1/me/policy-acceptances', $this->enrollment())->assertOk();
    }

    public function testStaffRequiresMfaOnTheCurrentSessionAndRejectsReplayExpiryAndRevocation(): void
    {
        $staff = $this->makeUser();
        $secret = Totp::secret();
        AccountSecurity::enrollStaff($staff, 'moderator', $secret, Totp::code($secret, intdiv(time(), 30) - 1));
        $this->assertFalse(query('staff_accounts')->first()->mfa_secret === $secret);
        $token = $this->token($staff);
        $this->withToken($token)->getJson('/api/v1/admin/reports')->assertStatus(403);
        $input = ['purpose' => 'staff', 'password' => 'password123', 'mfa_code' => Totp::code($secret, intdiv(time(), 30))];
        $this->postJson('/api/v1/auth/reauthenticate', [...$input, 'password' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/v1/auth/reauthenticate', $input)->assertOk();
        $this->postJson('/api/v1/auth/reauthenticate', $input)->assertStatus(422);
        $this->getJson('/api/v1/admin/reports')->assertOk();
        $this->asUser($staff)->getJson('/api/v1/admin/reports')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/admin/reports')->assertOk();
        query('staff_sessions')->where('user_id', $staff->id)->update(['expires_at' => now()->subSeconds(1)->toDateTimeString()]);
        $this->getJson('/api/v1/admin/reports')->assertStatus(403);
        AccountSecurity::revokeStaff($staff->id);
        $this->assertDatabaseCount('staff_sessions', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.enrolled', 'actor_type' => 'console']);
        $this->assertDatabaseHas('audit_events', ['action' => 'staff.revoked']);
    }

    public function testOrdinaryUsersCannotPromoteThemselvesOrObtainStaffProof(): void
    {
        $user = $this->makeUser();
        $this->asUser($user)->putJson('/api/v1/auth/profile', ['name' => 'Updated', 'role' => 'admin', 'status' => 'active'])->assertOk();
        $this->postJson('/api/v1/auth/reauthenticate', ['purpose' => 'staff', 'password' => 'password123'])->assertStatus(403);
        $this->getJson('/api/v1/admin/reports')->assertStatus(403);
        $this->assertDatabaseCount('staff_accounts', 0);
    }
}
