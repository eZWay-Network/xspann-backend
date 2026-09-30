<?php

namespace Tests\Feature;

use App\Models\{AuthIdentity, User};
use Spark\Utils\JWT;
use Spark\Facades\Cache;
use Spark\Http\Client\{Http, HttpResponse};
use Spark\Http\Client\Contracts\HttpResponseContract;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    private \OpenSSLAsymmetricKey $privateKey;
    private Http $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->mergeConfig(['app' => ['google_client_id' => 'test-client.apps.googleusercontent.com']]);
        $this->privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->http = new class extends Http {
            public int $calls = 0;
            public HttpResponse $response;

            public function get(string $url, array $params = []): HttpResponseContract
            {
                if ($url !== 'https://www.googleapis.com/oauth2/v1/certs' || $params !== []) {
                    throw new \LogicException('Unexpected Google request.');
                }
                $this->calls++;
                return $this->response;
            }
        };
        $this->http->response = new HttpResponse(
            body: json_encode(['google-key' => openssl_pkey_get_details($this->privateKey)['key']]),
            status: 200,
            headers: ['Cache-Control' => 'public, max-age=3600'],
        );
        $this->app->instance(Http::class, $this->http);
    }

    private function tokenFor(array $claims = [], string $keyId = 'google-key'): string
    {
        openssl_pkey_export($this->privateKey, $key);

        return JWT::encode([
            'iss' => 'https://accounts.google.com',
            'aud' => 'test-client.apps.googleusercontent.com',
            'sub' => '123456789',
            'iat' => time() - 5,
            'exp' => time() + 3600,
            'email' => 'alice@gmail.com',
            'email_verified' => true,
            'name' => 'Alice Google',
            'picture' => 'https://lh3.googleusercontent.com/avatar.jpg',
            ...$claims,
        ], $key, 'RS256', ['kid' => $keyId]);
    }

    public function testCreatesVerifiedAccountAndReturnsAUsableLoginToken(): void
    {
        $response = $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor(), 'status' => 'suspended', 'email' => 'forged@example.com'])
            ->assertOk()->assertJsonPath('data.user.email', 'alice@gmail.com');
        $user = User::find(1);
        $this->assertDatabaseHas('auth_identities', ['user_id' => $user->id, 'provider' => 'google', 'provider_id' => '123456789']);
        $this->assertSame('active', $user->status);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame('Alice Google', $user->name);
        $this->assertSame('https://lh3.googleusercontent.com/avatar.jpg', $user->avatar);
        $this->assertTrue((bool) preg_match('/^[a-z0-9_]{3,30}$/', $user->username));
        $this->assertFalse(array_key_exists('auth_identities', $response->json('data.user')));
        $this->assertSame(null, $user->password);
        $this->assertTrue(is_string($response->json('data.token_expires_at')));
        $this->withToken($response->json('data.token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor()])->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame(1, $this->http->calls);
        $this->assertDatabaseCount('auth_identities', 1);
        $this->assertSame(0, (int) app(\Spark\Queue\Queue::class)->getConnection()->query('SELECT COUNT(*) FROM jobs')->fetchColumn());
    }

    public function testLinksAnExistingVerifiedAccountWithoutReplacingItsProfileOrPassword(): void
    {
        $user = $this->makeUser();
        $user->update(['email' => 'alice@gmail.com', 'bio' => 'My profile']);
        $password = $user->password;
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor()])->assertOk()->assertJsonPath('data.user.id', $user->id);
        $user->refresh();
        $this->assertDatabaseHas('auth_identities', ['user_id' => $user->id, 'provider' => 'google', 'provider_id' => '123456789']);
        $this->assertSame($password, $user->password);
        $this->assertSame('Alice', $user->name);
        $this->assertSame('My profile', $user->bio);
        $this->assertDatabaseCount('users', 1);
    }

    public function testUnverifiedRegistrationCannotKeepItsOldCredentialsAfterGoogleVerification(): void
    {
        $user = $this->makeUser();
        $user->update(['email' => 'alice@gmail.com', 'email_verified_at' => null]);
        $oldToken = $this->token($user);
        query('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'old-reset', 'created_at' => now()]);
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor()])->assertOk();
        $this->assertTrue($user->refresh()->hasVerifiedEmail());
        $this->assertSame(null, $user->password);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->withToken($oldToken)->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->assertDatabaseCount('jwt_access_tokens', 1);
    }

    public function testReturningUsersAreIdentifiedByGoogleIdEvenWhenGoogleEmailChanges(): void
    {
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor()])->assertOk();
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor(['email' => 'changed@gmail.com'])])
            ->assertOk()->assertJsonPath('data.user.id', 1)->assertJsonPath('data.user.email', 'alice@gmail.com');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('auth_identities', ['user_id' => 1, 'provider' => 'google', 'provider_id' => '123456789']);
        $this->assertDatabaseCount('auth_identities', 1);
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor(['sub' => 'different-google-account'])])->assertStatus(409);
    }

    public function testInactiveAccountsCannotLoginOrBecomeVerified(): void
    {
        $user = $this->makeUser();
        $user->update(['email' => 'alice@gmail.com', 'status' => 'suspended', 'email_verified_at' => null]);
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor()])->assertStatus(403);
        $this->assertDatabaseCount('auth_identities', 0);
        $user->refresh();
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertDatabaseCount('jwt_access_tokens', 0);
    }

    public function testWorkspaceAccountsCanLinkButThirdPartyEmailCannotTakeOverAnExistingAccount(): void
    {
        $user = $this->makeUser();
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor(['email' => $user->email])])->assertStatus(409);
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor(['email' => $user->email, 'hd' => 'example.com'])])
            ->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor(['email' => 'new@example.net', 'sub' => 'new-account'])])->assertOk();
        $this->assertDatabaseCount('users', 2);
    }

    public function testRejectsMalformedUnsignedAndTamperedTokens(): void
    {
        $valid = $this->tokenFor();
        [$header, $payload, $signature] = explode('.', $valid);
        $forged = JWT::urlsafeB64Encode(json_encode(['email' => 'victim@gmail.com']));
        foreach (['not-a-jwt', "$header.$payload.", "$header.$forged.$signature", JWT::encode(['email' => 'alice@gmail.com'], str_repeat('a', 64), 'HS256', ['kid' => 'google-key'])] as $token) {
            $this->postJson('/api/v1/auth/social/google', ['token' => $token])->assertStatus(401);
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('jwt_access_tokens', 0);
    }

    public function testRejectsWrongAudienceIssuerAndInvalidDates(): void
    {
        foreach ([['aud' => 'other-app'], ['iss' => 'https://attacker.example'], ['exp' => time() - 1], ['iat' => time() + 100], ['exp' => null]] as $claims) {
            $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor($claims)])->assertStatus(401);
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function testRequiresVerifiedEmailSubjectAndAuthorizedPresenter(): void
    {
        foreach ([['email_verified' => false], ['email_verified' => 'true'], ['email' => 'invalid'], ['sub' => null], ['azp' => 'other-app']] as $claims) {
            $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor($claims)])->assertStatus(401);
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function testRefreshesRotatedKeysAndLimitsUnknownKeyRefreshes(): void
    {
        Cache::store('google.certificates', ['old-key' => openssl_pkey_get_details($this->privateKey)['key']], '+1 hour');
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor()])->assertOk();
        $this->assertSame(1, $this->http->calls);
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor(keyId: 'unknown')])->assertStatus(401);
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor(keyId: 'unknown-again')])->assertStatus(401);
        $this->assertSame(1, $this->http->calls);
    }

    public function testConfigurationAndGoogleAvailabilityErrorsDoNotCreateAccounts(): void
    {
        $this->app->mergeConfig(['app' => ['google_client_id' => null]]);
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor()])->assertStatus(503);
        $this->assertSame(0, $this->http->calls);
        $this->app->mergeConfig(['app' => ['google_client_id' => 'test-client.apps.googleusercontent.com']]);
        foreach ([new HttpResponse(status: 0), new HttpResponse(status: 500), new HttpResponse(body: 'invalid json', status: 200), new HttpResponse(body: '{"google-key":"invalid key"}', status: 200)] as $response) {
            $this->http->response = $response;
            $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor()])->assertStatus(503);
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function testExpiredGoogleKeyResponseIsNotCached(): void
    {
        $this->http->response = new HttpResponse(
            body: json_encode(['google-key' => openssl_pkey_get_details($this->privateKey)['key']]),
            status: 200,
            headers: ['Cache-Control' => 'public, max-age=3600', 'Age' => '3600'],
        );
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor()])->assertOk();
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor()])->assertOk();
        $this->assertSame(2, $this->http->calls);
    }

    public function testAuthIdentitiesBelongToTheirUserAndCascadeOnDeletion(): void
    {
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor()])->assertOk();
        $user = User::find(1);
        $identity = $user->authIdentities()->first();
        $this->assertSame($user->id, $identity->user->id);
        $this->assertSame('google', $identity->provider);
        $user->delete();
        $this->assertDatabaseCount('auth_identities', 0);
    }

    public function testOtherProvidersAreIsolatedAndUnsupportedRoutesAreRejected(): void
    {
        $user = $this->makeUser();
        AuthIdentity::create(['user_id' => $user->id, 'provider' => 'other', 'provider_id' => '123456789']);
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor()])->assertOk();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('auth_identities', 2);
        $this->postJson('/api/v1/auth/social/apple', ['token' => $this->tokenFor()])->assertStatus(404);
        $this->postJson('/api/v1/auth/google', ['token' => $this->tokenFor()])->assertStatus(404);
        $this->assertSame(1, $this->http->calls);
    }

    public function testIdentityConstraintsPreventDuplicateAndIncompleteLinks(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser('bob');
        AuthIdentity::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => '123']);

        foreach ([
            ['user_id' => $other->id, 'provider' => 'google', 'provider_id' => '123'],
            ['user_id' => $user->id, 'provider' => 'google', 'provider_id' => '456'],
            ['user_id' => $other->id, 'provider' => null, 'provider_id' => '456'],
            ['user_id' => $other->id, 'provider' => 'google', 'provider_id' => null],
        ] as $attributes) {
            $this->assertThrows(\PDOException::class, fn() => AuthIdentity::create($attributes));
        }
        $this->assertDatabaseCount('auth_identities', 1);
    }

    public function testAccountsWithoutPasswordsMustUsePasswordResetBeforePasswordLogin(): void
    {
        $response = $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor()])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'alice@gmail.com', 'password' => 'anything'])->assertStatus(422);
        $this->withToken($response->json('data.token'))->putJson('/api/v1/auth/password', [
            'current_password' => 'anything',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ])->assertStatus(422)->assertJsonValidationErrors(['current_password']);
        $this->assertSame(null, User::find(1)->password);

        query('password_reset_tokens')->insert(['email' => 'alice@gmail.com', 'token' => password_hash('reset-token', PASSWORD_DEFAULT), 'created_at' => now()]);
        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'alice@gmail.com',
            'token' => 'reset-token',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ])->assertOk();
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => 'alice@gmail.com', 'password' => 'newpassword'])->assertOk();
        $this->assertDatabaseCount('auth_identities', 1);
    }

    public function testNativeVerifierRejectsFutureNotBeforeAndMalformedDates(): void
    {
        foreach ([['nbf' => time() + 100], ['nbf' => 'tomorrow'], ['iat' => []], ['exp' => (string) (time() + 3600)]] as $claims) {
            $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor($claims)])->assertStatus(401);
        }
        $this->assertDatabaseCount('auth_identities', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function testAcceptsAnIndependentlySignedRsaToken(): void
    {
        [$header, $payload] = explode('.', $this->tokenFor());
        openssl_sign("$header.$payload", $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        $this->postJson('/api/v1/auth/social/google', ['token' => "$header.$payload." . JWT::urlsafeB64Encode($signature)])->assertOk();
    }

    public function testInputValidationAndThrottle(): void
    {
        foreach ([[], ['token' => []], ['token' => str_repeat('x', 8193)], ['token' => null], ['token' => '']] as $data) {
            $this->postJson('/api/v1/auth/social/google', $data)->assertStatus(422)->assertJsonValidationErrors(['token']);
        }
        $this->postJson('/api/v1/auth/social/google', ['token' => $this->tokenFor()])->assertStatus(429);
        $this->assertSame(0, $this->http->calls);
    }
}
