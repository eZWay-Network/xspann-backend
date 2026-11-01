<?php

namespace Tests\Feature;

use Spark\Database\{DB, Migration};
use Tests\Support\{ReleaseJourneys, ReleaseUpgrades};
use Tests\TestCase;

/** Opt-in: configuration supplies credentials, never the database to migrate or delete. */
class ReleaseMysqlTest extends TestCase
{
    use ReleaseJourneys, ReleaseUpgrades;

    private ?\PDO $mysqlServer = null;
    private ?string $disposableDatabase = null;
    private array $mysqlConnection = [];

    protected function setUp(): void
    {
        parent::setUp();
        $path = getenv('XPN_TEST_MYSQL_CONFIG');
        if (!$path) {
            $this->markTestSkipped('Set XPN_TEST_MYSQL_CONFIG to a private JSON connection file for disposable MySQL release checks.');
        }
        $input = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->mysqlConnection = array_intersect_key($input, array_flip(['host', 'port', 'username', 'password', 'charset']));
        $this->mysqlConnection['driver'] = 'mysql';
        $this->emptyReleaseDatabase();
        try {
            (new Migration(dirname(__DIR__, 2) . '/database/migrations'))->up(['force' => true]);
        } catch (\Throwable $error) {
            $this->dropDisposableDatabase();
            throw $error;
        }
    }

    protected function emptyReleaseDatabase(): void
    {
        $this->dropDisposableDatabase();
        $this->mysqlServer = (new DB([...$this->mysqlConnection, 'name' => 'information_schema']))->getPdo();
        $this->disposableDatabase = 'xpn_release_test_' . bin2hex(random_bytes(8));
        $this->mysqlServer->exec("CREATE DATABASE `{$this->disposableDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $connection = [...$this->mysqlConnection, 'name' => $this->disposableDatabase];
        $this->app->mergeConfig(['database' => ['default' => 'mysql', 'connections' => ['mysql' => $connection]], 'cache' => ['default' => 'database']]);
        $this->app->instance(DB::class, new DB($connection));
    }

    protected function tearDown(): void
    {
        try {
            $this->dropDisposableDatabase();
        } finally {
            parent::tearDown();
        }
    }

    private function dropDisposableDatabase(): void
    {
        if ($this->mysqlServer && $this->disposableDatabase && preg_match('/^xpn_release_test_[a-f0-9]{16}$/D', $this->disposableDatabase)) {
            $this->mysqlServer->exec("DROP DATABASE `{$this->disposableDatabase}`");
            $this->disposableDatabase = null;
        }
        $this->mysqlServer = null;
    }
}
