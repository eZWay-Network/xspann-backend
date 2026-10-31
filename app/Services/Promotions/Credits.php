<?php

namespace App\Services\Promotions;

use App\Services\Billing\Billing;
use Spark\Facades\DB;

class Credits
{
    /** Reconciles historical paid periods as well as new invoices; caller holds promotion mutex. */
    public static function sync(int $user): void
    {
        $periods = DB::table('billing_paid_periods as p')
            ->join('billing_subscriptions as s', 's.id', '=', 'p.subscription_id')
            ->join('billing_catalog as c', 'c.id', '=', 's.catalog_id')
            ->select(['p.*'])->where('s.user_id', $user)->where('s.environment', config('billing.environment'))
            ->where('c.plan_code', 'artist_pro')->orderBy('p.id')->get();

        foreach ($periods as $period) {
            $credit = Promotion::table('credits')->where('period_id', $period->id)->first();
            if (!$credit && !$period->revoked_at && (int) $period->boost_credit_cents === 500 && $period->starts_at <= Billing::timestamp()) {
                $id = DB::table('promotion_credits')->insert([
                    'user_id' => $user, 'period_id' => $period->id, 'environment' => config('billing.environment'),
                    'amount_cents' => 500, 'created_at' => Billing::timestamp(),
                ]);
                self::entry($id, null, "grant:$id", 'grant', 500, 0, 0);
                $credit = Promotion::table('credits')->where('id', $id)->first();
            }
            if ($credit && $period->revoked_at) {
                // Never revoke another period's credit or edit a historical grant.
                DB::table('promotion_credits')->where('id', $credit->id)->whereNull('revoked_at')
                    ->update(['revoked_at' => $period->revoked_at]);
                self::revokeAvailable($credit->id);
            }
        }
    }

    public static function held(int $user): bool
    {
        return Promotion::table('boosts')->where('user_id', $user)->where('staff_hold', true)->exists()
            || DB::table('billing_paid_periods as p')->join('billing_subscriptions as s', 's.id', '=', 'p.subscription_id')
            ->where('s.user_id', $user)->where('s.environment', config('billing.environment'))
            ->where('p.promotion_hold', true)->exists();
    }

    public static function usable(object $credit): bool
    {
        if ($credit->revoked_at || !$credit->period_id) {
            return false;
        }

        return DB::table('billing_paid_periods')->where('id', $credit->period_id)->whereNull('revoked_at')->exists();
    }

    public static function amounts(int $id): array
    {
        $row = DB::table('promotion_ledger')->where('credit_id', $id)
            ->selectRaw('COALESCE(SUM(available_delta), 0) AS available, COALESCE(SUM(reserved_delta), 0) AS reserved, COALESCE(SUM(used_delta), 0) AS used')->first();

        return ['available' => (int) $row->available, 'reserved' => (int) $row->reserved, 'used' => (int) $row->used];
    }

    public static function balance(int $user): array
    {
        return Promotion::transaction(function () use ($user): array {
            self::sync($user);
            $result = ['available_cents' => 0, 'reserved_cents' => 0, 'used_cents' => 0, 'held_cents' => 0, 'currency' => 'usd', 'spending_held' => self::held($user)];
            foreach (Promotion::table('credits')->where('user_id', $user)->get() as $credit) {
                $amounts = self::amounts($credit->id);
                $result['reserved_cents'] += $amounts['reserved'];
                $result['used_cents'] += $amounts['used'];
                if (self::usable($credit)) {
                    $result[$result['spending_held'] ? 'held_cents' : 'available_cents'] += $amounts['available'];
                }
            }

            return $result;
        });
    }

    public static function reserve(object $boost): void
    {
        self::sync($boost->user_id);
        abort_if(self::held($boost->user_id), 403, 'Promotion spending is held pending billing review.');
        $remaining = (int) $boost->price_cents;
        foreach (Promotion::table('credits')->where('user_id', $boost->user_id)->orderBy('id')->get() as $credit) {
            if (!self::usable($credit)) {
                continue;
            }
            $amount = min($remaining, self::amounts($credit->id)['available']);
            if ($amount <= 0) {
                continue;
            }
            DB::table('promotion_reservations')->insert(['boost_id' => $boost->id, 'credit_id' => $credit->id, 'amount_cents' => $amount]);
            self::entry($credit->id, $boost->id, "reserve:$boost->id:$credit->id", 'reservation', -$amount, $amount, 0);
            $remaining -= $amount;
            if (!$remaining) {
                return;
            }
        }
        abort(409, 'Insufficient available boost credit.');
    }

    public static function funded(object $boost): bool
    {
        if (!$boost->user_id || self::held($boost->user_id) || $boost->staff_hold) {
            return false;
        }
        $rows = DB::table('promotion_reservations')->where('boost_id', $boost->id)->get();
        if (!count($rows)) {
            return false;
        }
        foreach ($rows as $row) {
            $credit = Promotion::table('credits')->where('id', $row->credit_id)->first();
            if (!$credit || !self::usable($credit)) {
                return false;
            }
        }

        return true;
    }

    public static function activate(object $boost): void
    {
        foreach (DB::table('promotion_reservations')->where('boost_id', $boost->id)->where('status', 'reserved')->get() as $row) {
            $amount = (int) $row->amount_cents;
            self::entry($row->credit_id, $boost->id, "debit:$row->id", 'debit', 0, -$amount, $amount);
            DB::table('promotion_reservations')->where('id', $row->id)->update(['status' => 'charged']);
        }
    }

    public static function release(object $boost): void
    {
        foreach (DB::table('promotion_reservations')->where('boost_id', $boost->id)->where('status', 'reserved')->get() as $row) {
            $amount = (int) $row->amount_cents;
            self::entry($row->credit_id, $boost->id, "release:$row->id", 'release', $amount, -$amount, 0);
            DB::table('promotion_reservations')->where('id', $row->id)->update(['status' => 'released', 'returned_cents' => $amount]);
            self::revokeAvailable($row->credit_id);
        }
    }

    /** Target is cumulative, rounded upward once; retries and late incidents cannot over-return. */
    public static function remedy(object $boost, int $delivered): int
    {
        $target = min((int) $boost->price_cents, intdiv((int) $boost->price_cents * max(0, $boost->duration_seconds - $delivered) + $boost->duration_seconds - 1, (int) $boost->duration_seconds));
        $rows = DB::table('promotion_reservations')->where('boost_id', $boost->id)->where('status', 'charged')->orderBy('id')->get();
        $returned = 0;
        foreach ($rows as $row) {
            $returned += (int) $row->returned_cents;
        }
        $remaining = max(0, $target - $returned);
        foreach ($rows as $row) {
            $amount = min($remaining, $row->amount_cents - $row->returned_cents);
            if ($amount <= 0) {
                continue;
            }
            $total = $row->returned_cents + $amount;
            self::entry($row->credit_id, $boost->id, "reversal:$row->id:$total", 'reversal', $amount, 0, -$amount);
            DB::table('promotion_reservations')->where('id', $row->id)->update(['returned_cents' => $total]);
            self::revokeAvailable($row->credit_id);
            $remaining -= $amount;
        }

        return $target;
    }

    private static function revokeAvailable(int $id): void
    {
        $credit = Promotion::table('credits')->where('id', $id)->first();
        if (!$credit || self::usable($credit)) {
            return;
        }
        $amount = self::amounts($id)['available'];
        if ($amount > 0) {
            $sequence = DB::table('promotion_ledger')->where('credit_id', $id)->where('kind', 'revocation')->count();
            self::entry($id, null, "revocation:$id:$sequence", 'revocation', -$amount, 0, 0);
        }
    }

    private static function entry(int $credit, ?int $boost, string $key, string $kind, int $available, int $reserved, int $used): void
    {
        DB::table('promotion_ledger')->insert([
            'credit_id' => $credit, 'boost_id' => $boost, 'entry_key' => $key, 'kind' => $kind,
            'available_delta' => $available, 'reserved_delta' => $reserved, 'used_delta' => $used,
            'created_at' => Billing::timestamp(),
        ]);
    }
}
