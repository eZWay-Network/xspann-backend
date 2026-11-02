<?php

namespace Tests\Feature;

use App\Services\{AccountSecurity, Totp};
use App\Services\Staff\StaffException;
use Spark\Console\{Commands, Console, Prompt};
use Spark\Facades\{DB, Hash};
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
            public static bool $valid = false;
            public static string $secret = '';

            public static function message(string $message, string $type = 'normal'): void
            {
                self::$messages[] = $message;
                if (preg_match('/secret: ([A-Z2-7]+)$/', $message, $matches)) {
                    self::$secret = $matches[1];
                }
            }

            public static function ask(string $question, string $default = ''): string
            {
                if (self::$valid) {
                    return Totp::code(self::$secret, intdiv(time(), 30));
                }
                $code = '123456';
                while (Totp::counter(self::$secret, $code) !== null) {
                    $code = str_pad((string) ((int) $code + 1), 6, '0', STR_PAD_LEFT);
                }

                return $code;
            }
        };
        $this->prompt::$messages = [];
        $this->prompt::$valid = false;
        $this->prompt::$secret = '';
        $this->app->instance(Prompt::class, $this->prompt);
    }

    public function testInvalidAuthenticatorCodeRaisesADomainErrorAndPreservesExistingAccess(): void
    {
        $user = $this->makeUser();
        $secret = Totp::secret();
        AccountSecurity::enrollStaff($user, 'admin', $secret, Totp::code($secret, intdiv(time(), 30) - 1));
        $before = (array) DB::table('staff_accounts')->first();

        $this->assertThrows(StaffException::class, fn() => $this->console->executeCommand('staff:enroll', ['user' => (string) $user->id, 'role' => 'admin']));

        $this->assertSame($before, (array) DB::table('staff_accounts')->first());
        $this->assertCount(2, $this->prompt::$messages);
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
        $secret = Totp::secret();
        AccountSecurity::enrollStaff($user, 'support', $secret, Totp::code($secret, intdiv(time(), 30) - 1));

        foreach (['staff:enroll', 'staff:revoke'] as $command) {
            foreach ([true, false, '', '0', '-1', 'invalid'] as $id) {
                $this->assertThrows(\InvalidArgumentException::class, fn() => $this->console->executeCommand($command, ['user' => $id]));
            }
        }
        $this->assertDatabaseHas('staff_accounts', ['user_id' => $user->id, 'enabled' => true, 'version' => 1]);
        $this->assertSame([], $this->prompt::$messages);
    }

    public function testValidEnrollmentAndResetStoreTheConfirmedFactorAndRevokeSessions(): void
    {
        $user = $this->makeUser();
        $this->prompt::$valid = true;

        foreach ([1, 2] as $version) {
            DB::table('staff_sessions')->insert([
                'user_id' => $user->id,
                'session_hash' => str_repeat((string) $version, 64),
                'expires_at' => now()->addMinutes(15),
                'created_at' => now(),
            ]);
            $this->console->executeCommand('staff:enroll', ['user' => (string) $user->id, 'role' => 'admin']);
            $member = DB::table('staff_accounts')->first();
            $this->assertSame($this->prompt::$secret, Hash::decrypt($member->mfa_secret));
            $this->assertSame($version, (int) $member->version);
            $this->assertSame('admin', $member->role);
            $this->assertTrue((int) $member->last_counter >= intdiv(time(), 30) - 1);
            $this->assertDatabaseCount('staff_sessions', 0);
        }
    }
}
