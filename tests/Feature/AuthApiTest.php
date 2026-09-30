<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountNotifications;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    public function testRegistrationLoginProfileAndLogout(): void
    {
        $response = $this->postJson('/api/v1/auth/register', ['username' => 'alice', 'email' => 'alice@example.com', 'password' => 'password123', 'password_confirmation' => 'password123']);
        $response->assertStatus(201)->assertJsonPath('data.user.username', 'alice');
        $this->assertTrue(password_verify('password123', User::find(1)->password));
        $this->assertSame(null, $response->json('data.token'));
        $this->assertDatabaseCount('jwt_access_tokens', 0);
        $this->assertSame(1, (int) app(\Spark\Queue\Queue::class)->getConnection()->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
        $this->postJson('/api/v1/auth/login', ['email' => 'alice@example.com', 'password' => 'password123'])->assertStatus(403);
        $this->getJson((new AccountNotifications())->verificationUrl(User::find(1)))->assertOk();
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'alice@example.com', 'password' => 'password123'])->assertOk();
        $this->withToken($login->json('data.token'));
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', 'alice@example.com');
        $this->putJson('/api/v1/auth/profile', ['bio' => 'A creator', 'status' => 'admin'])->assertOk()->assertJsonPath('data.bio', 'A creator');
        $this->assertDatabaseHas('users', ['id' => 1, 'status' => 'active']);
        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->assertDatabaseCount('jwt_access_tokens', 0);
    }

    public function testValidationAndInvalidCredentials(): void
    {
        $this->makeUser();
        $this->postJson('/api/v1/auth/register', [])->assertStatus(422)->assertJsonValidationErrors(['username', 'email', 'password']);
        $this->postJson('/api/v1/auth/register', ['username' => 'alice', 'email' => 'alice@example.com', 'password' => 'password123', 'password_confirmation' => 'different'])->assertStatus(422)->assertJsonValidationErrors(['username', 'email', 'password']);
        $this->postJson('/api/v1/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong'])->assertStatus(422);
        $this->withToken('invalid')->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function testRegistrationCannotExposeAnotherAccountsPrivateMedia(): void
    {
        $this->useS3(['temporary_urls' => true]);
        $this->makeUser();
        $this->postJson('/api/v1/auth/register', [
            'username' => 'other',
            'email' => 'other@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'avatar' => storage('s3')->url('videos/1/private.mp4'),
        ])->assertStatus(422)->assertJsonValidationErrors(['avatar']);
        $this->assertDatabaseCount('users', 1);
    }

    public function testInactiveAndUnverifiedUsersCannotLoginOrUseExistingTokens(): void
    {
        $user = $this->makeUser();
        $token = $this->token($user);
        $user->update(['status' => 'suspended']);
        $credentials = ['email' => $user->email, 'password' => 'password123'];
        $this->postJson('/api/v1/auth/login', $credentials)->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(403);
        $user->update(['status' => 'active', 'email_verified_at' => null]);
        $this->postJson('/api/v1/auth/login', $credentials)->assertStatus(403);
        $this->getJson('/api/v1/auth/me')->assertStatus(403);
        $this->postJson('/api/v1/auth/token/refresh')->assertStatus(403);
        $user->update(['email_verified_at' => now()]);
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function testRefreshRevokesOnlyCurrentToken(): void
    {
        $user = $this->makeUser();
        $first = $this->token($user);
        $second = $this->token($user);
        $this->withToken($first);
        $new = $this->postJson('/api/v1/auth/token/refresh')->assertOk()->json('data.token');
        $this->withToken($first)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->withToken($second)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($new)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertDatabaseCount('jwt_access_tokens', 2);
    }

    public function testPasswordChangePreservesCurrentTokenAndRevokesOthers(): void
    {
        $user = $this->makeUser();
        $first = $this->token($user);
        $second = $this->token($user);
        $this->withToken($first);
        $this->putJson('/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'newpassword', 'password_confirmation' => 'newpassword'])->assertStatus(422);
        $this->putJson('/api/v1/auth/password', ['current_password' => 'password123', 'password' => 'newpassword', 'password_confirmation' => 'newpassword'])->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($second)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->assertTrue(password_verify('newpassword', $user->refresh()->password));
    }

    public function testResetTokensExpireAreSingleUseAndRevokeAllSessions(): void
    {
        $user = $this->makeUser();
        $token = $this->token($user);
        query('password_reset_tokens')->insert(['email' => $user->email, 'token' => password_hash('reset-secret', PASSWORD_DEFAULT), 'created_at' => now()]);
        $data = ['email' => $user->email, 'token' => 'reset-secret', 'password' => 'newpassword', 'password_confirmation' => 'newpassword'];
        $this->postJson('/api/v1/auth/reset-password', [...$data, 'token' => 'wrong'])->assertStatus(422);
        $this->postJson('/api/v1/auth/reset-password', $data)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $data)->assertStatus(422);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function testForgotPasswordDoesNotDiscloseExistenceAndQueuesEmail(): void
    {
        $user = $this->makeUser();
        $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        $this->assertSame($known->json(), $unknown->json());
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(422);
    }

    public function testSignedVerificationAndTampering(): void
    {
        $user = $this->makeUser();
        $notifications = new AccountNotifications();
        $url = $notifications->verificationUrl($user);
        $this->getJson($url . 'bad')->assertStatus(403);
        $this->getJson($notifications->verificationUrl($user, time() - 10))->assertStatus(403);
        $this->getJson($url . '&unexpected=1')->assertStatus(403)->assertJsonPath('message', 'Invalid signature.');
        $this->getJson($url)->assertOk();
        $this->assertTrue($user->refresh()->email_verified_at !== null);
    }

    public function testAuthRateLimitAndCors(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', [])->assertStatus(429);
        $this->postJson('/api/v1/auth/register', [])->assertStatus(422);
        $this->options('/api/v1/auth/login', headers: ['Origin' => 'http://localhost:3000', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization,content-type'])
            ->assertStatus(204)->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $response = $this->options('/api/v1/auth/login', headers: ['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST'])->assertStatus(403);
        $this->assertFalse(array_key_exists('access-control-allow-origin', array_change_key_case($response->response->getHeaders())));
    }
    public function testResendVerificationQueuesAnEmailAndRecognizesVerifiedUsers(): void
    {
        $user = $this->makeUser();
        $user->update(['email_verified_at' => null]);
        $this->postJson('/api/v1/auth/email/verification-notification', ['email' => $user->email])->assertOk();
        $this->assertSame(1, (int) app(\Spark\Queue\Queue::class)->getConnection()->query("SELECT COUNT(*) FROM jobs WHERE queue = 'default'")->fetchColumn());
        $user->update(['email_verified_at' => now()]);
        $verified = $this->postJson('/api/v1/auth/email/verification-notification', ['email' => $user->email])->assertOk();
        $unknown = $this->postJson('/api/v1/auth/email/verification-notification', ['email' => 'unknown@example.com'])->assertOk();
        $this->assertSame($verified->json(), $unknown->json());
        $this->assertSame(1, (int) app(\Spark\Queue\Queue::class)->getConnection()->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
    }

    public function testExpiredPasswordResetIsRejected(): void
    {
        $user = $this->makeUser();
        query('password_reset_tokens')->insert(['email' => $user->email, 'token' => password_hash('old', PASSWORD_DEFAULT), 'created_at' => '2000-01-01 00:00:00']);
        $this->postJson('/api/v1/auth/reset-password', ['email' => $user->email, 'token' => 'old', 'password' => 'newpassword', 'password_confirmation' => 'newpassword'])->assertStatus(422);
        $this->assertTrue(password_verify('password123', $user->refresh()->password));
    }

}
