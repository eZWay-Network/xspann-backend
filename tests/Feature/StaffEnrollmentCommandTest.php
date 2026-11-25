<?php

namespace Tests\Feature;

use App\Services\AccountSecurity;
use App\Services\Staff\StaffException;
use Spark\Console\{Commands, Console, Prompt};
use Spark\Facades\DB;
use Tests\TestCase;

class StaffEnrollmentCommandTest extends TestCase
{
    private Console $console;
    private Prompt $prompt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Commands::class, new Commands());
        require dirname(__DIR__, 2) . '/routes/console.php';
        $this->console = new Console(command());
        $this->prompt = new class extends Prompt {
            public static array $messages = [];

            public static function message(string $message, string $type = 'normal'): void
            {
                self::$messages[] = $message;
            }

            public static function ask(string $question, string $default = ''): string
            {
                throw new \RuntimeException('Password-only enrollment must not prompt for an authenticator.');
            }
        };
        $this->prompt::$messages = [];
        $this->app->instance(Prompt::class, $this->prompt);
    }

    public function testLegacyRoleCannotBeAssignedAndExistingAccessIsPreserved(): void
    {
        $user = $this->makeUser();
        AccountSecurity::enrollStaff($user, 'super_admin');
        $before = (array) DB::table('staff_accounts')->first();
        $this->assertThrows(StaffException::class, fn() => AccountSecurity::enrollStaff($user, 'admin'));
        $this->assertSame($before, (array) DB::table('staff_accounts')->first());
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function testIneligibleAccountsAndMissingUsersNeverSendAnHttpResponse(): void
    {
        $user = $this->makeUser();
        $user->update(['email_verified_at' => null]);
        $this->assertThrows(StaffException::class, fn() => $this->console->executeCommand('staff:enroll', ['user' => (string) $user->id]));
        $this->assertThrows(\InvalidArgumentException::class, fn() => $this->console->executeCommand('staff:enroll', ['user' => '999999']));
        $this->assertDatabaseCount('staff_accounts', 0);
    }

    public function testMissingUserOptionValueCannotEnrollOrRevokeUserOne(): void
    {
        $user = $this->makeUser();
        AccountSecurity::enrollStaff($user, 'moderator');
        foreach (['staff:enroll', 'staff:revoke'] as $command) {
            foreach ([true, false, '', '0', '-1', 'invalid'] as $id) {
                $this->assertThrows(\InvalidArgumentException::class, fn() => $this->console->executeCommand($command, ['user' => $id]));
            }
        }
        $this->assertDatabaseHas('staff_accounts', ['user_id' => $user->id, 'enabled' => true, 'version' => 1]);
        $this->assertSame([], $this->prompt::$messages);
    }

    public function testPasswordOnlyEnrollmentAndResetRevokeExistingSessions(): void
    {
        $user = $this->makeUser();
        foreach ([1, 2] as $version) {
            DB::table('staff_sessions')->insert([
                'user_id' => $user->id,
                'session_hash' => str_repeat((string) $version, 64),
                'expires_at' => now()->addMinutes(15),
                'created_at' => now(),
            ]);
            $this->console->executeCommand('staff:enroll', ['user' => (string) $user->id, 'role' => 'super_admin']);
            $member = DB::table('staff_accounts')->first();
            $this->assertSame(null, $member->mfa_secret);
            $this->assertSame($version, (int) $member->version);
            $this->assertSame('super_admin', $member->role);
            $this->assertSame(-1, (int) $member->last_counter);
            $this->assertDatabaseCount('staff_sessions', 0);
        }
    }
}
