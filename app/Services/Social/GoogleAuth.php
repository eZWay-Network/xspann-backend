<?php

namespace App\Services\Social;

use App\Models\{AuthIdentity, User};
use App\Services\StorageService;
use Spark\Facades\{Cache, DB, Hash, Http, Lock};
use Spark\Utils\JWT;
use function array_key_exists;
use function count;
use function in_array;
use function is_int;
use function is_object;
use function is_string;
use function strlen;

class GoogleAuth
{
    public function user(string $token): User
    {
        $claims = $this->verify($token);
        $email = strtolower($claims['email']);

        return Lock::withLock(
            'accounts.google.' . hash('sha256', $email),
            fn() => DB::transaction(function () use ($claims, $email) {
                $identity = AuthIdentity::where('provider', 'google')
                    ->where('provider_id', $claims['sub'])
                    ->first() ?: null;

                $user = $identity?->user;

                if (!$user) {
                    $user = User::where('email', $email)->first();
                    if ($user) {
                        abort_if($user->authIdentities()->where('provider', 'google')->exists(), 409, 'This account is linked to another Google account.');

                        // Google cannot establish current ownership of third-party email addresses.
                        abort_unless(str_ends_with($email, '@gmail.com') || !empty($claims['hd']), 409, 'Please sign in with your email and password.');
                    }
                }

                if (!$user) {
                    do {
                        $username = 'user_' . Hash::random(8);
                    } while (User::where('username', $username)->exists());

                    $picture = $claims['picture'] ?? null;
                    $avatar = is_string($picture) && strlen($picture) <= 255
                        && filter_var($picture, FILTER_VALIDATE_URL) && parse_url($picture, PHP_URL_SCHEME) === 'https'
                        ? $picture : null;

                    $user = User::create([
                        'email' => $email,
                        'email_verified_at' => now(),
                        'status' => 'active',
                        'username' => $username,
                        'name' => is_string($claims['name'] ?? null) && $claims['name'] !== '' ? mb_substr($claims['name'], 0, 255) : $username,
                        'avatar' => $avatar,
                    ]);

                    StorageService::validateOwner($user->avatar, $user->id, 'avatar', 'avatars');
                }

                abort_unless($user->status === 'active', 403, 'This account is not active.');

                if (!$user->hasVerifiedEmail()) {
                    abort_unless(
                        strtolower($user->email) === $email,
                        409,
                        'Please verify your account email address first.'
                    );

                    // Discard credentials from an unverified registration claiming this email.
                    $user->update([
                        'email_verified_at' => now(),
                        'password' => null,
                        'remember_token' => null
                    ]);

                    query('jwt_access_tokens')->where('user_id', $user->id)->delete();
                    query('password_reset_tokens')->where('email', $user->email)->delete();
                }

                if (!$identity) {
                    AuthIdentity::create([
                        'user_id' => $user->id,
                        'provider' => 'google',
                        'provider_id' => $claims['sub'],
                    ]);
                }

                return $user;
            }),
            timeout: 10,
            waitTimeout: 5
        );
    }

    private function verify(string $token): array
    {
        $clientId = config('app.google_client_id');

        abort_unless(
            is_string($clientId) && $clientId !== '',
            503,
            'Google sign-in is not configured.'
        );

        try {
            $header = JWT::getHeader($token);
        } catch (\UnexpectedValueException | \DomainException) {
            abort(401, 'Invalid Google ID token.');
        }

        abort_unless(
            is_object($header) && ($header->alg ?? null) === 'RS256' && is_string($header->kid ?? null),
            401,
            'Invalid Google ID token.'
        );

        $certificates = $this->certificates();
        if (!isset($certificates[$header->kid]) && Cache::add('google.certificates.refresh', true, '+1 minute')) {
            Cache::erase('google.certificates');

            $certificates = $this->certificates();
        }

        abort_unless(isset($certificates[$header->kid]), 401, 'Invalid Google ID token.');

        try {
            $claims = (array) JWT::decode($token, $certificates[$header->kid], allowedAlgorithms: ['RS256']);
        } catch (\UnexpectedValueException | \DomainException) {
            abort(401, 'Invalid Google ID token.');
        }

        // Native Google sign-in uses the Android client as azp and the Web client as aud.
        $allowedPresenters = [$clientId, ...config('app.google_allowed_presenter_ids', [])];

        abort_unless(
            in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            && ($claims['aud'] ?? null) === $clientId
            && (!array_key_exists('azp', $claims)
                || (is_string($claims['azp']) && in_array($claims['azp'], $allowedPresenters, true)))
            && is_int($claims['exp'] ?? null) && $claims['exp'] > time()
            && is_int($claims['iat'] ?? null) && $claims['iat'] <= time()
            && (!isset($claims['nbf']) || (is_int($claims['nbf']) && $claims['nbf'] <= time()))
            && is_string($claims['sub'] ?? null) && $claims['sub'] !== '' && strlen($claims['sub']) <= 255
            && ($claims['email_verified'] ?? false) === true
            && is_string($claims['email'] ?? null) && strlen($claims['email']) <= 255
            && filter_var($claims['email'], FILTER_VALIDATE_EMAIL)
            && (!isset($claims['hd']) || (is_string($claims['hd']) && $claims['hd'] !== '')),
            401,
            'Invalid Google ID token.'
        );

        return $claims;
    }

    private function certificates(): array
    {
        if (Cache::has('google.certificates', true)) {
            return Cache::retrieve('google.certificates');
        }

        $response = Http::timeout(5)
            ->withRetry(0)
            ->get('https://www.googleapis.com/oauth2/v1/certs');

        $certificates = $response->json();

        abort_unless(
            $response->status() === 200 && is_array($certificates) && $certificates && count(array_filter($certificates, 'is_string')) === count($certificates),
            503,
            'Google sign-in is temporarily unavailable.'
        );

        foreach ($certificates as $certificate) {
            abort_unless(
                openssl_pkey_get_public($certificate) !== false,
                503,
                'Google sign-in is temporarily unavailable.'
            );
        }

        preg_match('/max-age=(\d+)/i', (string) $response->header('Cache-Control', ''), $matches);

        $ttl = min(86400, (int) ($matches[1] ?? 300) - max(0, (int) $response->header('Age', 0)));
        if ($ttl > 0) {
            Cache::store('google.certificates', $certificates, "+$ttl seconds");
        }

        return $certificates;
    }
}
