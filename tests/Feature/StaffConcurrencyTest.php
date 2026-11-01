<?php

namespace Tests\Feature;

use App\Services\Staff\Sessions;
use Spark\Facades\Hash;
use Spark\Foundation\Application;
use Tests\TestCase;

class StaffConcurrencyTest extends TestCase
{
    protected function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->mergeConfig(['database' => ['connections' => ['sqlite' => ['file' => $this->storagePath . '/staff-race.sqlite']]]]);

        return $app;
    }

    public function testConcurrentAdministratorDeparturesKeepOneEnabledAdministrator(): void
    {
        $ids = [];

        foreach (['first', 'second'] as $name) {
            $user = $this->makeUser($name);
            $ids[] = $user->id;
            query('staff_accounts')->insert([
                'user_id' => $user->id, 'role' => 'admin', 'version' => 1, 'enabled' => true,
                'mfa_secret' => Hash::encrypt('TEST-ONLY-UNUSED'), 'last_counter' => -1,
                'created_at' => Sessions::timestamp(), 'updated_at' => Sessions::timestamp(),
            ]);
            query('staff_sessions')->insert([
                'user_id' => $user->id, 'session_hash' => hash('sha256', $name), 'membership_version' => 1,
                'mfa_at' => Sessions::timestamp(), 'mfa_expires_at' => Sessions::timestamp(900),
                'expires_at' => Sessions::timestamp(1800), 'absolute_expires_at' => Sessions::timestamp(28800),
                'created_at' => Sessions::timestamp(),
            ]);
        }
        $config = [];

        foreach (['app', 'auth', 'database', 'session', 'cache', 'queue', 'staff'] as $key) {
            $config[$key] = config($key);
        }
        $file = $this->storagePath . '/staff-race.json';
        file_put_contents($file, json_encode($config, JSON_THROW_ON_ERROR));
        $processes = $streams = [];

        try {
            foreach ($ids as $id) {
                $processes[] = proc_open([PHP_BINARY, dirname(__DIR__) . '/Fixtures/staff-mutation.php', $file, (string) $id], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                fclose($pipes[0]);
                $streams[] = [$pipes[1], $pipes[2]];
            }
            $statuses = [];

            foreach ($streams as [$output, $error]) {
                $statuses[] = (int) stream_get_contents($output);
                $this->assertSame('', stream_get_contents($error));
                fclose($output);
                fclose($error);
            }
            sort($statuses);
            $this->assertSame([200, 409], $statuses);
            $this->assertSame(1, query('staff_accounts')->where('role', 'admin')->where('enabled', true)->count());
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_close($process);
                }
            }
        }
    }
}
