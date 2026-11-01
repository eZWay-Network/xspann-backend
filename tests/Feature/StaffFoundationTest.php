<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\{AccountSecurity, Totp};
use App\Services\Staff\{Permissions, Sessions};
use Spark\Facades\{DB, Hash, Session};
use Tests\TestCase;

class StaffFoundationTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
    private const HEADERS = ['X-Inertia' => 'true', 'Accept' => 'text/html'];

    private static function headers(): array
    {
        $manifest = dirname(__DIR__, 2) . '/public/build/.vite/manifest.json';

        return [...self::HEADERS, 'X-Inertia-Version' => is_file($manifest) ? hash_file('xxh128', $manifest) : ''];
    }

    protected function createApplication(): \Spark\Foundation\Application
    {
        $app = parent::createApplication();
        $app->mergeConfig(['app' => ['url' => 'http://localhost']]);

        return $app;
    }

    private function staff(string $name = 'operator', string $role = 'admin'): User
    {
        $user = $this->makeUser($name);
        DB::table('staff_accounts')->insert([
            'user_id' => $user->id, 'role' => $role, 'enabled' => true, 'version' => 1,
            'mfa_secret' => Hash::encrypt(self::SECRET), 'last_counter' => -1,
            'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
        ]);

        return $user;
    }

    private function page(string $path = '/')
    {
        return $this->get($path, self::headers());
    }

    private function submit(string $path, array $data = [], string $method = 'POST')
    {
        return $this->request($method, $path, $data, [...self::headers(), 'X-CSRF-TOKEN' => session('csrf_token', ''), 'Referer' => 'http://localhost' . (str_contains($path, '/settings/') ? '/settings/team' : $path)]);
    }

    private function signIn(User $user): void
    {
        $this->page('/login')->assertOk();
        $this->submit('/login', ['email' => $user->email, 'password' => 'password123'])->assertStatus(303);
        $this->submit('/challenge', ['mfa_code' => Totp::code(self::SECRET, intdiv(time(), 30))])->assertStatus(303);
    }

    public function testInitialHtmlAndInertiaLoginArePrivateAndContainNoIdentity(): void
    {
        $this->get('/login')->assertOk()->assertHeader('Cache-Control', 'no-store, private, max-age=0');
        $response = $this->page('/login')->assertOk()->assertJsonPath('component', 'auth/login')->assertJsonPath('props.auth', null);
        $this->assertTrue(strlen($response->json('props.csrf_token')) >= 32);
        $this->assertSame([], $response->json('props.navigation'));
    }

    public function testCsrfCoversLoginChallengeAndLogoutIncludingErrorResponses(): void
    {
        foreach (['login', 'challenge', 'logout'] as $path) {
            $this->post('/' . $path, [], self::headers())->assertStatus(419)
                ->assertHeader('Cache-Control', 'no-store, private, max-age=0')->assertJsonPath('component', 'error');
        }
    }

    public function testPasswordAloneAndCustomerJwtCannotReadProtectedProps(): void
    {
        $user = $this->staff();
        $this->asUser($user)->page()->assertStatus(409)->assertHeader('X-Inertia-Location', 'http://localhost/login');
        $this->flushHeaders();
        $this->page('/login');
        $this->submit('/login', ['email' => $user->email, 'password' => 'password123'])->assertStatus(303);
        $this->assertSame(null, session('staff_user_id'));
        $this->assertDatabaseCount('staff_sessions', 0);
        $this->page()->assertStatus(409);
    }

    public function testBrowserMfaCreatesANonceBoundSessionAndExplicitOverview(): void
    {
        $user = $this->staff();
        $this->makeVideo($user);
        $this->signIn($user);
        $response = $this->page()->assertOk()->assertJsonPath('component', 'overview')
            ->assertJsonPath('props.auth.user.id', $user->id)->assertJsonPath('encryptHistory', true);
        $this->assertSame(1, $response->json('props.overview.metrics.0.value'));
        $this->assertSame(1, $response->json('props.overview.metrics.2.value'));
        $this->assertFalse(str_contains($response->content(), self::SECRET));
        $this->assertFalse(str_contains($response->content(), 'password123'));
        $record = DB::table('staff_sessions')->first();
        $this->assertSame(Sessions::hash(), $record->session_hash);
        $this->assertFalse($record->session_hash === hash('sha256', ''));
        $this->assertSame(1, (int) $record->membership_version);
    }

    public function testMfaReplayAndExpiryCannotReadDataButLogoutRemainsAvailable(): void
    {
        $user = $this->staff();
        $this->signIn($user);
        $this->submit('/challenge', ['password' => 'password123', 'mfa_code' => Totp::code(self::SECRET, intdiv(time(), 30))])->assertStatus(303);
        $this->page('/challenge')->assertJsonPath('props.errors.mfa_code', 'Enter a fresh six-digit authenticator code.');
        DB::table('staff_sessions')->where('user_id', $user->id)->update(['mfa_expires_at' => Sessions::timestamp(-1)]);
        $this->page()->assertStatus(409)->assertHeader('X-Inertia-Location', 'http://localhost/challenge');
        $this->page('/challenge')->assertOk()->assertJsonPath('props.auth', null)->assertJsonPath('props.navigation', []);
        $this->submit('/logout')->assertStatus(409);
        $this->assertSame(null, session('staff_user_id'));
        $this->assertTrue(DB::table('staff_sessions')->whereNotNull('revoked_at')->exists());
    }

    public function testDisabledRoleChangedIdleAndAbsoluteExpiredSessionsFailClosed(): void
    {
        foreach (['revoked', 'version', 'idle', 'absolute', 'disabled'] as $index => $failure) {
            Session::flush();
            $user = $this->staff('operator' . $index);
            $this->signIn($user);
            match ($failure) {
                'revoked' => DB::table('staff_sessions')->where('user_id', $user->id)->update(['revoked_at' => Sessions::timestamp()]),
                'version' => DB::table('staff_accounts')->where('user_id', $user->id)->update(['version' => 2]),
                'idle' => DB::table('staff_sessions')->where('user_id', $user->id)->update(['expires_at' => Sessions::timestamp(-1)]),
                'absolute' => DB::table('staff_sessions')->where('user_id', $user->id)->update(['absolute_expires_at' => Sessions::timestamp(-1)]),
                'disabled' => DB::table('staff_accounts')->where('user_id', $user->id)->update(['enabled' => false]),
            };
            $this->page()->assertStatus(409);
        }
    }

    public function testRolesAreExactAndSequentialUsersDoNotSharePermissions(): void
    {
        $this->assertFalse(Permissions::allows('unknown', 'dashboard.view'));
        $this->assertFalse(Permissions::allows('admin', 'unknown.ability'));
        $this->assertFalse(Permissions::allows('moderator', 'billing.view'));

        foreach (Permissions::roles() as $index => $role) {
            Session::flush();
            $user = $this->staff('role' . $index, $role);
            $this->signIn($user);
            $response = $this->page()->assertOk()->assertJsonPath('props.auth.role', $role);
            $this->assertSame(Permissions::forRole($role), $response->json('props.auth.permissions'));
            $this->page('/settings/team')->assertStatus($role === 'admin' ? 200 : 403);

            if ($role !== 'admin') {
                $this->assertFalse(str_contains(json_encode($response->json('props.navigation')), 'Team & access'));
            }
        }
    }

    public function testValidationNeverFlashesCredentialsAndRejectsUnknownFields(): void
    {
        $this->page('/login');
        $this->submit('/login', ['email' => 'bad', 'password' => 'private-password', 'google_token' => 'private-google', 'role' => 'admin'])->assertStatus(303);
        $this->assertFalse(str_contains(json_encode($_SESSION), 'private-password'));
        $this->assertFalse(str_contains(json_encode($_SESSION), 'private-google'));
        $this->page('/login')->assertJsonPath('props.auth', null);
    }

    public function testTeamInvitationIsOneUseAndLastAdminCannotBeDisabled(): void
    {
        $admin = $this->staff();
        $invitee = $this->makeUser('invitee');
        $this->signIn($admin);
        $this->submit('/settings/team/invitations', ['email' => $invitee->email, 'role' => 'support', 'reason' => 'Customer support team'])->assertStatus(303);
        $response = $this->page('/settings/team')->assertOk();
        $link = $response->json('flash.invitation_url');
        $this->assertTrue(str_contains($link, '/enroll/'));
        $this->assertSame(null, $this->page('/settings/team')->json('flash.invitation_url'));
        $this->assertSame(0, DB::table('staff_accounts')->where('user_id', $invitee->id)->count());
        $id = DB::table('staff_accounts')->where('user_id', $admin->id)->value('id');
        $this->submit('/settings/team/' . $id, ['action' => 'disable', 'role' => 'admin', 'version' => 1, 'reason' => 'Try to remove final admin'], 'PATCH')->assertStatus(303);
        $this->assertDatabaseHas('staff_accounts', ['user_id' => $admin->id, 'enabled' => true, 'version' => 1]);
    }

    public function testPartialRequestsKeepCsrfAndPermissionStateFreshAndRejectOldAssets(): void
    {
        $this->signIn($this->staff());
        $this->get('/', [...self::headers(), 'X-Inertia-Partial-Component' => 'overview', 'X-Inertia-Partial-Data' => 'filters'])->assertOk()
            ->assertJsonPath('props.auth.role', 'admin')->assertJsonPath('props.csrf_token', session('csrf_token'));
        $this->get('/', [...self::headers(), 'X-Inertia-Version' => 'stale-version'])->assertStatus(409)->assertHeader('X-Inertia-Location', 'http://localhost/');
    }

    public function testLegacyGrantsAreNeverBrowserIdentityAndForwardMigrationPreservesMembership(): void
    {
        $user = $this->staff();
        $before = DB::table('staff_accounts')->where('user_id', $user->id)->first();
        DB::table('staff_sessions')->insert(['user_id' => $user->id, 'session_hash' => hash('sha256', ''), 'expires_at' => now()->addMinutes(15), 'created_at' => now()]);
        Session::put('staff_user_id', $user->id);
        $this->page()->assertStatus(409);
        $migration = require dirname(__DIR__, 2) . '/database/migrations/migration_2026_11_01_140000_staff_browser_foundation.php';
        $migration->up();
        $after = DB::table('staff_accounts')->where('user_id', $user->id)->first();
        $this->assertSame($before->mfa_secret, $after->mfa_secret);
        $this->assertSame($before->last_counter, $after->last_counter);
        $this->assertSame($before->role, $after->role);
    }
    public function testPendingSignInCannotSurviveACredentialChange(): void
    {
        $user = $this->staff();
        $this->page('/login');
        $this->submit('/login', ['email' => $user->email, 'password' => 'password123'])->assertStatus(303);
        $user->update(['password' => 'ChangedPassword123!']);
        $this->submit('/challenge', ['mfa_code' => Totp::code(self::SECRET, intdiv(time(), 30))])->assertStatus(403);
        $this->assertDatabaseCount('staff_sessions', 0);
    }

    public function testPasswordAndAuthenticatorChangesRevokeEveryBrowserSession(): void
    {
        $user = $this->staff();
        $this->signIn($user);
        $this->submit('/settings/security/password', [
            'password' => 'password123',
            'mfa_code' => Totp::code(self::SECRET, intdiv(time(), 30) + 1),
            'new_password' => 'ChangedPassword123!',
        ])->assertStatus(409);
        $this->assertTrue(Hash::verify('ChangedPassword123!', User::find($user->id)->password));
        $this->assertDatabaseCount('staff_sessions', 0);
        $this->assertSame(null, session('staff_user_id'));

        Session::flush();
        $other = $this->staff('factor');
        $this->signIn($other);
        $this->submit('/settings/security/factor', [
            'password' => 'password123',
            'mfa_code' => Totp::code(self::SECRET, intdiv(time(), 30) + 1),
        ])->assertStatus(303);
        $secret = $this->page('/settings/security')->assertOk()->json('props.factor_secret');
        $this->assertTrue(is_string($secret) && strlen($secret) >= 32);
        $this->assertSame(null, $this->page('/settings/security')->json('props.factor_secret'));
        $this->submit('/settings/security/factor/complete', [
            'mfa_code' => Totp::code($secret, intdiv(time(), 30)),
        ])->assertStatus(409);
        $this->assertDatabaseCount('staff_sessions', 0);
        $member = DB::table('staff_accounts')->where('user_id', $other->id)->first();
        $this->assertSame(2, (int) $member->version);
        $this->assertSame($secret, Hash::decrypt($member->mfa_secret));
    }

    public function testTeamPaginationUsesOwnPreferencesAndRejectsInvalidPages(): void
    {
        $admin = $this->staff();
        for ($index = 0; $index < 12; $index++) {
            $this->staff('member' . $index, 'support');
        }
        DB::table('staff_preferences')->insert(['user_id' => $admin->id, 'page_size' => 10, 'created_at' => Sessions::timestamp(), 'updated_at' => Sessions::timestamp()]);
        $this->signIn($admin);
        $first = $this->page('/settings/team')->assertOk();
        $this->assertCount(10, $first->json('props.members'));
        $first->assertJsonPath('props.pagination.members.total', 13);
        $second = $this->page('/settings/team?page=2')->assertOk();
        $this->assertCount(3, $second->json('props.members'));
        $second->assertJsonPath('props.pagination.members.current_page', 2);
        $this->page('/settings/team?page=-1')->assertStatus(422);
    }

    public function testPopulatedUpgradePreservesLegacyHistoryAndNeverDowngradesBrowserCredentials(): void
    {
        $admin = $this->staff();
        $video = $this->makeVideo($admin);
        $this->signIn($admin);
        $member = DB::table('staff_accounts')->where('user_id', $admin->id)->first();
        DB::table('staff_sessions')->insert([
            'user_id' => $admin->id, 'session_hash' => str_repeat('a', 64),
            'expires_at' => now()->addMinutes(15), 'created_at' => now(),
        ]);
        $migration = require dirname(__DIR__, 2) . '/database/migrations/migration_2026_11_01_140000_staff_browser_foundation.php';
        $migration->down();
        $this->assertDatabaseCount('staff_sessions', 1);
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('videos', ['id' => $video->id]);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
        $this->assertDatabaseHas('staff_accounts', ['user_id' => $admin->id, 'role' => $member->role, 'mfa_secret' => $member->mfa_secret, 'last_counter' => $member->last_counter]);
        $this->assertDatabaseHas('staff_sessions', ['session_hash' => str_repeat('a', 64), 'membership_version' => null]);
    }

    public function testCustomerDeletionProtectsTheLastAdministratorAndPreservesTheProof(): void
    {
        $admin = $this->staff();
        $token = AccountSecurity::issue($admin->id, 'email_deletion', 300);
        $this->postJson('/api/v1/account/deletion-confirmations', ['token' => $token])->assertStatus(409);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'status' => 'active']);
        $this->assertDatabaseCount('privacy_requests', 0);
        $this->assertDatabaseHas('account_action_tokens', ['token_hash' => hash('sha256', $token)]);

        $this->staff('remaining');
        $this->postJson('/api/v1/account/deletion-confirmations', ['token' => $token])->assertStatus(202);
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'status' => 'deleting']);
        $this->assertDatabaseHas('staff_accounts', ['user_id' => $admin->id, 'enabled' => false, 'version' => 2]);
    }

    public function testRootBrowserRoutesPreserveApiAndPublicUtilityResponses(): void
    {
        $this->page('/')->assertStatus(409)->assertHeader('X-Inertia-Location', 'http://localhost/login');
        $this->page('/login')->assertOk()->assertJsonPath('component', 'auth/login');
        $this->page('/xpn-admin')->assertStatus(404)->assertJsonPath('component', 'error');
        $this->page('/missing-page')->assertStatus(404)->assertHeader('Cache-Control', 'no-store, private, max-age=0');
        $health = $this->get('/up')->assertOk()->assertJsonPath('status', 'ok');
        $this->assertFalse(str_contains($health->content(), '"component"'));
        $this->get('/api/docs')->assertOk();
        $this->postJson('/api/v1/auth/login', [])->assertStatus(422);
        $this->getJson('/api/v1/does-not-exist')->assertStatus(404);
    }

    public function testRootLoginDestinationsOnlyAcceptProtectedBrowserGets(): void
    {
        foreach (['/', '/?period=30', '/attention', '/settings/profile', '/settings/security?source=menu'] as $path) {
            $this->assertSame($path, Sessions::destination($path));
        }

        foreach ([null, '', 'https://example.com/settings/profile', '//example.com/settings/profile',
            '/%2fexample.com', '/%5cexample.com', '/settings/../login', '/settings/profile%0a',
            '/login', '/challenge', '/enroll/secret', '/logout', '/api/v1/auth/me', '/up',
            '/settings/security/password', '/xpn-admin', '/unknown'] as $path) {
            $this->assertSame('/', Sessions::destination($path));
        }
    }

}
