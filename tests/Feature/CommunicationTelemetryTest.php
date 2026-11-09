<?php

namespace Tests\Feature;

use App\Services\{Conversations, Realtime};
use Spark\Foundation\Application;
use Spark\Pulse\{Entry, Pulse};
use Tests\Support\CommunicationFixtures;
use Tests\TestCase;

class CommunicationTelemetryTest extends TestCase
{
    use CommunicationFixtures;

    protected function createApplication(): Application
    {
        $app = parent::createApplication();
        $app->mergeConfig([
            'database' => ['connections' => ['telemetry' => [
                'driver' => 'sqlite',
                'file' => $this->storagePath . '/telemetry.sqlite',
            ]]],
            'pulse' => ['enabled' => true, 'storage' => ['driver' => 'database', 'connection' => 'telemetry']],
        ]);

        return $app;
    }

    public function testPrivateRequestsAndBufferedInsertIdsWithRecordingEnabled(): void
    {
        if (!class_exists(Pulse::class)) {
            $this->markTestSkipped('Optional telemetry package is absent.');
        }
        $a = $this->makeUser();
        $b = $this->makeUser('bob');
        $this->mutual($a, $b);
        $thread = Conversations::open($a->id, $b->id);
        $marker = 'private-fixture-' . bin2hex(random_bytes(12));
        $this->asUser($a)->postJson("/api/v1/conversations/$thread->id/messages", $this->messageInput($marker))->assertCreated();

        $pulse = app(Pulse::class);
        $pulse->flush();
        for ($i = 0; $i < 49; $i++) {
            $pulse->record(Entry::make('fixture', ['i' => $i]));
        }
        Realtime::record($b->id, 'notifications.updated', $b->id, 0);
        $pulse->flush();

        $this->assertSame(49, $pulse->storage()->get('fixture')['total']);
        $telemetry = \Spark\Facades\DB::connection('telemetry')->getPdo();
        $search = $telemetry->prepare('SELECT COUNT(*) FROM pulse_entries WHERE content LIKE ?');
        $search->execute(['%' . $marker . '%']);
        $this->assertSame(0, (int) $search->fetchColumn());
        $event = query('realtime_outbox')->orderBy('id', 'DESC')->first();
        $this->assertSame((int) $event->id, (int) $event->version);
    }
}
