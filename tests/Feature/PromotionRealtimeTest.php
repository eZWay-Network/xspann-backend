<?php

namespace Tests\Feature;

use App\Services\Realtime;
use App\Services\Promotions\{Boosts, Placements, Promotion};
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class PromotionRealtimeTest extends TestCase
{
    use PromotionFixtures;

    public function testPromotionTransitionsCommitOwnerHintsAndIdempotencyDoesNotRepeatThem(): void
    {
        [$owner, $artist] = $this->paidArtist();
        $this->promotionSetup();
        $staff = $this->promotionStaff();
        $boost = Boosts::create($owner, $this->boostInput($this->makeVideo($owner)->id));
        $this->assertDatabaseHas('realtime_outbox', ['user_id' => $owner->id, 'type' => 'boost.updated', 'resource_id' => $boost->id, 'version' => 1]);
        Boosts::submit($owner, $boost->id, 1, 'realtime-submit-key');
        $count = query('realtime_outbox')->count();
        Boosts::submit($owner, $boost->id, 1, 'realtime-submit-key');
        $this->assertSame($count, query('realtime_outbox')->count());
        $boost = Boosts::decide($staff, $boost->id, ['version' => 2, 'decision' => 'approve', 'reason' => 'Reviewed eligible creative.']);
        $this->assertDatabaseHas('realtime_outbox', ['user_id' => $owner->id, 'type' => 'boost.updated', 'resource_id' => $boost->id, 'version' => $boost->version]);
        $placement = query('promotion_placements')->where('boost_id', $boost->id)->first();
        $this->assertDatabaseHas('realtime_outbox', ['user_id' => $owner->id, 'type' => 'placement.updated', 'resource_id' => $placement->id, 'version' => $placement->version]);

        Placements::create($staff, [
            'artist_id' => $artist->id,
            'month' => gmdate('Y-m', strtotime('first day of next month UTC')),
            'type' => 'feature',
            'network_account' => 'ezway-instagram',
            'capacity_confirmed' => true,
            'reason' => 'Confirmed both monthly obligations.',
        ]);
        foreach (query('promotion_placements')->where('type', '!=', 'boost')->get() as $row) {
            $this->assertDatabaseHas('realtime_outbox', ['user_id' => $owner->id, 'type' => 'placement.updated', 'resource_id' => $row->id, 'version' => $row->version]);
        }
        $this->assertSame(0, query('realtime_outbox')->where('user_id', $staff->id)->whereIn('type', ['boost.updated', 'placement.updated'])->count());

        $before = query('realtime_outbox')->count();
        $this->assertThrows(\RuntimeException::class, function () use ($owner): void {
            Promotion::transaction(function () use ($owner): void {
                Boosts::create($owner, $this->boostInput($this->makeVideo($owner)->id));
                throw new \RuntimeException('Rollback the entire workflow.');
            });
        });
        $this->assertSame($before, query('realtime_outbox')->count());
        $this->assertDatabaseCount('promotion_boosts', 1);
    }

    public function testPromotionHintsRecheckOwnershipEnvironmentAndSessionBeforePublishing(): void
    {
        [$owner] = $this->paidArtist();
        $this->promotionSetup();
        $boost = $this->submittedBoost($owner);
        $placement = query('promotion_placements')->where('boost_id', $boost->id)->first();
        $this->app->mergeConfig(['relay' => ['apps' => [['key' => 'test-key', 'secret' => 'test-secret']]]]);
        $this->asUser($owner)->postJson('/api/v1/realtime/session')->assertOk();
        $relay = new class extends \Spark\Relay\Relay {
            public array $events = [];

            public function trigger(array|string $channels, string $event, array|string $data, ?string $socketId = null): mixed
            {
                $this->events[] = [$channels, $event, $data];

                return true;
            }
        };
        $this->app->instance(\Spark\Relay\Relay::class, $relay);
        foreach (['boost.updated' => $boost->id, 'placement.updated' => $placement->id] as $type => $id) {
            $event = query('realtime_outbox')->where('type', $type)->where('resource_id', $id)->orderBy('id', 'DESC')->first();
            $this->assertTrue(Realtime::permitted($event));
            $foreign = clone $event;
            $foreign->user_id = $this->makeUser(str_replace('.', '-', $type))->id;
            $this->assertFalse(Realtime::permitted($foreign));
            $this->app->mergeConfig(['billing' => ['environment' => 'live']]);
            $this->assertFalse(Realtime::permitted($event));
            $this->app->mergeConfig(['billing' => ['environment' => 'test']]);
            Realtime::publish($event->id);
            Realtime::publish($event->id);
        }
        $this->assertCount(2, $relay->events);
        $this->assertSame(['event_id', 'type', 'occurred_at', 'resource_id', 'version'], array_keys($relay->events[0][2]));
        $this->postJson('/api/v1/auth/logout')->assertOk();
        Boosts::cancel($owner, $boost->id, $boost->version);
        Realtime::drain();
        $this->assertCount(2, $relay->events);
    }
}
