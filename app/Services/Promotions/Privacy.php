<?php

namespace App\Services\Promotions;

use App\Http\Resources\PromotionResource;
use App\Services\Billing\Billing;
use Spark\Facades\DB;

class Privacy
{
    public static function export(int $user): array
    {
        return [
            'boosts' => array_map([PromotionResource::class, 'boost'], Reports::boosts(Promotion::table('boosts')->where('user_id', $user)->get()->all())),
            'placements' => array_map([PromotionResource::class, 'placement'], Reports::placements(Promotion::table('placements')->where('user_id', $user)->get()->all())),
            'ledger' => array_map([PromotionResource::class, 'ledger'], DB::table('promotion_ledger')
                ->whereIn('credit_id', Promotion::table('credits')->select('id')->where('user_id', $user))->get()->all()),
        ];
    }

    /** Run before the final account transaction: no promotion -> account lock inversion. */
    public static function deleting(int $user): void
    {
        Promotion::transaction(function () use ($user): void {
            foreach (Promotion::table('boosts')->where('user_id', $user)->get() as $boost) {
                if ($boost->status === 'draft') {
                    Boosts::update($boost, ['status' => 'cancelled', 'stop_reason' => 'account_deleted', 'settled_at' => Billing::timestamp()]);
                } else {
                    Boosts::tick($boost->id);
                }
                Promotion::releaseSlot($boost->id);
            }
            foreach (['promotion_credits', 'promotion_boosts', 'promotion_enrollments'] as $table) {
                DB::table($table)->where('user_id', $user)->whereNull('deleted_at')->update(['deleted_at' => Billing::timestamp()]);
            }
            DB::table('promotion_operations')->where('user_id', $user)->delete();
            $ids = DB::table('promotion_placements')->where('user_id', $user)->pluck('id');
            if ($ids) {
                DB::table('promotion_evidence')->whereIn('placement_id', $ids)->delete();
                DB::table('promotion_placements')->whereIn('id', $ids)->update(['status' => 'cancelled', 'reason' => null,
                    'extension_consent' => null, 'version' => DB::raw('version + 1'), 'updated_at' => Billing::timestamp()]);
            }
        });
    }

    public static function prune(): void
    {
        $cutoff = Billing::timestamp(time() - max(1, (int) config('billing.retention_days', 2555)) * 86400);
        Promotion::transaction(function () use ($cutoff): void {
            $boosts = Promotion::table('boosts')->whereNull('user_id')->where('deleted_at', '<', $cutoff)
                ->where('staff_hold', false)->whereNotNull('settled_at')->pluck('id');
            if ($boosts) {
                DB::table('promotion_boosts')->whereIn('id', $boosts)->delete();
            }
            $credits = Promotion::table('credits')->whereNull('user_id')->where('deleted_at', '<', $cutoff)
                ->whereNotIn('id', DB::table('promotion_reservations')->select('credit_id'))->pluck('id');
            if ($credits) {
                DB::table('promotion_credits')->whereIn('id', $credits)->delete();
            }
            Promotion::table('enrollments')->whereNull('user_id')->where('deleted_at', '<', $cutoff)->delete();
            Promotion::table('operations')->where('created_at', '<', $cutoff)->delete();
        });
    }

}
