<?php

namespace Tests\Feature;

use App\Services\PendingUploads;
use RuntimeException;
use Spark\Facades\{Cache, Lock, Queue};
use Spark\Foundation\Application;
use Tests\TestCase;

class CacheLockTest extends TestCase
{
    protected function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->mergeConfig([
            'database' => ['connections' => ['sqlite' => ['file' => $this->storagePath . '/database.sqlite']]],
            'cache' => [
                'connections' => [
                    'database' => [
                        'driver' => 'database',
                        'connection' => null,
                        'lock_connection' => null,
                        'table' => 'caches',
                        'lock_table' => 'locks',
                    ]
                ]
            ],
        ]);

        return $app;
    }

    public function testMediaLocksExcludeOtherOwnersAndReleaseAfterFailure(): void
    {
        foreach (['file', 'database'] as $store) {
            $this->app->mergeConfig(['cache' => ['default' => $store]]);

            $this->assertThrows(RuntimeException::class, function (): void {
                PendingUploads::locked(1, function (): void {
                    $this->assertFalse(Lock::lock('media.attach.1', 30)->get());
                    Cache::flush();
                    $this->assertFalse(Lock::lock('media.attach.1', 30)->get());

                    throw new RuntimeException('Attachment failed.');
                });
            });

            $this->assertTrue(PendingUploads::locked(1, fn() => true));
        }
    }
}
