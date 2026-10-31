<?php

namespace App\Jobs;

use App\Services\Billing\Billing;
use App\Services\Promotions\{Boosts, Credits, Placements, Promotion};
use Spark\Facades\{DB, Log};
use Spark\Queue\Contracts\JobInterface;
use Spark\Queue\Dispatchable;

class PromotionMaintenance implements JobInterface
{
    use Dispatchable;

    public function handle(): void
    {
        // Stable key batches prevent one bad record from starving later accounts/campaigns.
        $this->each(DB::table('users')->whereIn('status', ['active', 'suspended']), fn($row) => Credits::sync($row->id), 'credits');
        $this->each(Promotion::table('boosts')->where('status', '!=', 'draft'), fn($row) => Boosts::tick($row->id), 'boost');
        $this->each(Promotion::table('placements')->where('type', '!=', 'boost'), fn($row) => Placements::tick(Placements::find($row->id)), 'placement');
        \App\Services\Promotions\Privacy::prune();
        DB::table('promotion_events')->where('created_at', '<', Billing::timestamp(time() - max(1, (int) config('promotions.event_retention_days')) * 86400))->delete();
    }

    private function each(\Spark\Database\QueryBuilder $query, callable $action, string $kind): void
    {
        $last = 0;
        do {
            $rows = (clone $query)->where('id', '>', $last)->orderBy('id')->limit(100)->get();
            foreach ($rows as $row) {
                $last = $row->id;
                try {
                    Promotion::transaction(fn() => $action($row));
                } catch (\Throwable) {
                    Log::error('Promotion {kind} maintenance failed for local ID {id}; retained for retry.', ['kind' => $kind, 'id' => $row->id]);
                }
            }
        } while (count($rows) === 100);
    }
}
