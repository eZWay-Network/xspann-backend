<?php

namespace App\Services\Promotions;

use App\Http\Resources\PromotionResource;
use App\Models\User;
use App\Services\AuditTrail;
use App\Services\Billing\Billing;
use Spark\Facades\DB;

class Boosts
{
    public static function find(int $id, ?int $owner = null): object
    {
        $query = Promotion::table('boosts')->where('id', $id);
        if ($owner !== null) {
            $query->where('user_id', $owner);
        }
        $row = $query->first();
        abort_unless((bool) $row, 404, 'Boost not found.');

        return $row;
    }

    public static function create(User $user, array $input): object
    {
        return Promotion::transaction(function () use ($user, $input): object {
            $artist = Promotion::owner($user);
            $data = self::creativeInput($artist->id, $input);
            $id = DB::table('promotion_boosts')->insert([
                ...$data, 'user_id' => $user->id, 'artist_id' => $artist->id,
                'environment' => config('billing.environment'), 'surface' => config('promotions.surface'),
                'price_cents' => config('promotions.price_cents'), 'duration_seconds' => config('promotions.duration_seconds'),
                'created_at' => Billing::timestamp(), 'updated_at' => Billing::timestamp(),
            ]);

            return self::find($id);
        });
    }

    private static function creativeInput(int $artist, array $input): array
    {
        abort_unless($input['offer_id'] === config('promotions.offer_id'), 422, 'Unknown promotion offer.');
        abort_unless($input['rights_confirmed'] === true && $input['rights_version'] === config('promotions.rights_version'), 422, 'Accept the current advertising rights declaration.');
        abort_unless((bool) Promotion::creative($artist, (int) $input['video_id']), 422, 'Select your own eligible public video.');
        $start = Promotion::at($input['starts_at']);
        Promotion::future($start);

        return [
            'eligibility_revision' => Invalidations::revision(), 'video_id' => (int) $input['video_id'], 'offer_id' => $input['offer_id'], 'starts_at' => $start,
            'ends_at' => Billing::timestamp(Promotion::seconds($start) + (int) config('promotions.duration_seconds')),
            'rights_version' => $input['rights_version'], 'rights_confirmed_at' => Billing::timestamp(),
        ];
    }

    public static function edit(User $user, int $id, array $input): object
    {
        return Promotion::transaction(function () use ($user, $id, $input): object {
            $boost = self::find($id, $user->id);
            Promotion::version($boost, (int) $input['version']);
            abort_unless($boost->status === 'draft', 409, 'Only drafts are editable. New creative requires a new campaign and review.');
            Promotion::owner($user);
            $data = self::creativeInput($boost->artist_id, $input);
            self::update($boost, $data);

            return self::find($id);
        });
    }

    public static function submit(User $user, int $id, int $version, string $key): array
    {
        return Promotion::transaction(fn() => Promotion::once($user->id, 'boost.submit', $key, ['id' => $id, 'version' => $version], function () use ($user, $id, $version): array {
            $boost = self::find($id, $user->id);
            Promotion::version($boost, $version);
            abort_unless($boost->status === 'draft', 409, 'Only a draft can be submitted.');
            Promotion::owner($user);
            abort_unless(Availability::healthy(), 503, 'Bookings are disabled until the web surface has current availability evidence.');
            abort_unless(!Invalidations::cutoff($boost) && Promotion::creative($boost->artist_id, $boost->video_id) && $boost->rights_version === config('promotions.rights_version'), 422, 'Creative or advertising rights are no longer eligible.');
            abort_unless($boost->offer_id === config('promotions.offer_id') && (int) $boost->price_cents === (int) config('promotions.price_cents')
                && (int) $boost->duration_seconds === (int) config('promotions.duration_seconds'), 409, 'The offer changed. Create a draft using the current offer.');
            Promotion::future($boost->starts_at);
            Credits::reserve($boost);
            Promotion::reserveSlot($boost, $boost->starts_at, $boost->ends_at);
            self::update($boost, ['status' => 'pending_review']);
            DB::table('promotion_placements')->insert([
                'boost_id' => $id, 'user_id' => $user->id, 'artist_id' => $boost->artist_id,
                'environment' => $boost->environment, 'type' => 'boost', 'destination' => $boost->surface,
                'status' => 'pending_review', 'starts_at' => $boost->starts_at, 'ends_at' => $boost->ends_at,
                'created_at' => Billing::timestamp(), 'updated_at' => Billing::timestamp(),
            ]);
            AuditTrail::record('promotion.boost.submitted', ['boost_id' => $id], $user->id);

            return ['id' => $id, 'status' => 'pending_review', 'version' => $version + 1, 'reserved_cents' => (int) $boost->price_cents];
        }));
    }

    public static function decide(User $staff, int $id, array $input): object
    {
        return Promotion::transaction(function () use ($staff, $id, $input): object {
            $boost = self::find($id);
            Promotion::version($boost, (int) $input['version']);
            switch ($input['decision']) {
                case 'approve':
                    abort_unless($boost->status === 'pending_review' && $boost->starts_at > Billing::timestamp(), 409, 'Approve a pending campaign before its requested start.');
                    abort_unless(!Invalidations::cutoff($boost) && Promotion::creative($boost->artist_id, $boost->video_id) && Credits::funded($boost)
                        && $boost->rights_version === config('promotions.rights_version'), 409, 'Creative, rights or funding is ineligible.');
                    abort_unless(Availability::healthy(), 503, 'Promotion surface is unavailable.');
                    self::update($boost, ['status' => 'scheduled']);
                    break;
                case 'reject':
                    abort_unless(in_array($boost->status, ['pending_review', 'scheduled'], true) && !$boost->activated_at, 409, 'This campaign cannot be rejected before activation.');
                    self::stop($boost, 'rejected', 'review_rejected');
                    break;
                case 'restrict':
                case 'hold':
                    abort_unless(in_array($boost->status, ['pending_review', 'scheduled', 'active', 'interrupted'], true), 409, 'Campaign is already terminal.');
                    if ($input['decision'] === 'hold') {
                        self::update($boost, ['staff_hold' => true]);
                        Invalidations::record('user', $boost->user_id);
                        $boost = self::find($id);
                    }
                    self::tick($id);
                    $boost = self::find($id);
                    self::stop($boost, 'interrupted', $input['decision'] === 'hold' ? 'staff_hold' : 'review_restricted', $input['decision'] !== 'hold');
                    break;
                case 'release_hold':
                    abort_unless((bool) $boost->staff_hold, 409, 'No staff hold exists.');
                    self::update($boost, ['staff_hold' => false]);
                    self::settle(self::find($id));
                    break;
            }
            DB::table('promotion_placements')->where('boost_id', $id)->update(['reason' => $input['reason']]);
            AuditTrail::record('promotion.boost.' . $input['decision'], ['boost_id' => $id, 'reason' => $input['reason']], $staff->id, 'staff');

            return self::find($id);
        });
    }

    public static function cancel(User $user, int $id, int $version): object
    {
        return Promotion::transaction(function () use ($user, $id, $version): object {
            $boost = self::find($id, $user->id);
            Promotion::version($boost, $version);
            abort_unless(in_array($boost->status, ['draft', 'pending_review', 'scheduled', 'active', 'interrupted'], true), 409, 'Campaign is already terminal.');
            self::tick($id);
            self::stop(self::find($id), 'cancelled', 'owner_cancelled');
            AuditTrail::record('promotion.boost.cancelled', ['boost_id' => $id], $user->id);

            return self::find($id);
        });
    }

    /** Caller holds the promotion mutex. No retroactive activation after worker downtime. */
    public static function tick(int $id): void
    {
        $boost = self::find($id);
        $now = Billing::timestamp();
        if (in_array($boost->status, ['pending_review', 'scheduled', 'active'], true)) {
            $cutoff = Invalidations::cutoff($boost);
            $eligible = !$cutoff && Promotion::creative($boost->artist_id, $boost->video_id)
                && Credits::funded($boost) && $boost->rights_version === config('promotions.rights_version');
            if (!$eligible) {
                if ($cutoff && $boost->status === 'active' && Availability::configured()) {
                    Availability::accrue($boost, min($now, $cutoff));
                }
                self::stop($boost, 'interrupted', 'eligibility_changed');

                return;
            }
            if ($boost->status === 'pending_review' && $boost->starts_at <= $now) {
                self::stop($boost, 'rejected', 'review_expired');

                return;
            }
            if ($boost->status === 'scheduled' && $boost->starts_at <= $now) {
                if ($boost->ends_at <= $now) {
                    self::stop($boost, 'interrupted', 'activation_missed');

                    return;
                }
                if (!Availability::healthy()) {
                    return;
                }
                Credits::activate($boost);
                self::update($boost, ['status' => 'active', 'activated_at' => $boost->activated_at ?? $now, 'checked_at' => $now]);
                $boost = self::find($id);
            }
            if ($boost->status === 'active') {
                if (Availability::configured()) {
                    Availability::accrue($boost, $now);
                }
                DB::table('promotion_boosts')->where('id', $id)->update(['checked_at' => $now]);
                if ($boost->ends_at <= $now || Availability::delivered($boost) >= $boost->duration_seconds) {
                    $delivered = Availability::delivered($boost);
                    self::stop(self::find($id), $delivered >= $boost->duration_seconds ? 'completed' : 'interrupted', 'window_ended', false);
                    if ($delivered >= $boost->duration_seconds) {
                        self::settle(self::find($id));
                    }
                }
            }
        } elseif (in_array($boost->status, ['interrupted', 'cancelled', 'rejected', 'completed'], true)
            && !$boost->settled_at && $boost->settle_after && $boost->settle_after <= $now) {
            self::settle($boost);
        }
        $boost = self::find($id);
        if ($boost->settled_at && $boost->activated_at && !$boost->staff_hold && $boost->user_id && !Credits::held($boost->user_id)) {
            // Includes completed campaigns affected by a late incident; never re-debits credit.
            Credits::remedy($boost, Availability::delivered($boost));
            if ($boost->status === 'completed' && Availability::delivered($boost) < $boost->duration_seconds) {
                self::update($boost, ['status' => 'interrupted', 'stop_reason' => 'late_incident']);
            }
        }
    }

    private static function stop(object $boost, string $status, string $reason, bool $settle = true): void
    {
        Promotion::releaseSlot($boost->id);
        Credits::release($boost);
        self::update($boost, [
            'status' => $status, 'stop_reason' => $reason,
            'settle_after' => $boost->settle_after ?? Billing::timestamp(time() + (int) config('promotions.remedy_grace_seconds')),
        ]);
        if ($settle) {
            self::settle(self::find($boost->id));
        }
    }

    private static function settle(object $boost): void
    {
        if ($boost->staff_hold || ($boost->user_id && Credits::held($boost->user_id))) {
            return;
        }
        if ($boost->activated_at) {
            Credits::remedy($boost, Availability::delivered($boost));
        }
        if (!$boost->settled_at) {
            self::update($boost, ['settled_at' => Billing::timestamp()]);
        }
    }

    public static function remedy(User $staff, int $id, array $input, string $key): array
    {
        return Promotion::transaction(fn() => Promotion::once($staff->id, 'boost.remedy', $key, ['id' => $id, ...$input], function () use ($staff, $id, $input): array {
            $boost = self::find($id);
            Promotion::version($boost, (int) $input['version']);
            abort_unless($boost->status === 'interrupted' && $boost->activated_at, 409, 'Only an interrupted, activated campaign needs this remedy.');
            abort_if($boost->staff_hold || Credits::held($boost->user_id), 409, 'Resolve the recorded spending hold first.');
            if ($input['action'] === 'credit') {
                self::settle($boost);
            } else {
                abort_if($boost->settled_at || $boost->settle_after <= Billing::timestamp(), 409, 'The credit settlement window has closed.');
                $placement = Promotion::table('placements')->where('boost_id', $id)->first();
                if ($input['action'] === 'propose_extension') {
                    $start = Promotion::at($input['starts_at'] ?? '');
                    Promotion::future($start);
                    $remaining = $boost->duration_seconds - Availability::delivered($boost);
                    abort_unless($remaining > 0, 409, 'No undelivered time remains.');
                    Placements::proposal($placement, $start, Billing::timestamp(Promotion::seconds($start) + $remaining), $input['reason']);
                    self::update($boost, []);
                } else {
                    Promotion::version($placement, (int) ($input['placement_version'] ?? 0));
                    abort_unless($placement->extension_consent === 'accepted', 409, 'Explicit owner consent to these exact dates is required.');
                    Promotion::future($placement->extension_starts_at);
                    $remaining = $boost->duration_seconds - Availability::delivered($boost);
                    abort_unless(Promotion::seconds($placement->extension_ends_at) - Promotion::seconds($placement->extension_starts_at) === $remaining, 409, 'Delivery evidence changed. Obtain consent to revised dates.');
                    abort_unless(Availability::healthy() && !Invalidations::cutoff($boost) && Promotion::creative($boost->artist_id, $boost->video_id) && Credits::funded($boost), 409, 'The campaign cannot resume.');
                    Promotion::reserveSlot($boost, $placement->extension_starts_at, $placement->extension_ends_at);
                    self::update($boost, ['status' => 'scheduled', 'starts_at' => $placement->extension_starts_at,
                        'ends_at' => $placement->extension_ends_at, 'checked_at' => null, 'settle_after' => null, 'stop_reason' => null]);
                    DB::table('promotion_placements')->where('id', $placement->id)->update(['extension_consent' => 'applied', 'version' => DB::raw('version + 1')]);
                }
            }
            AuditTrail::record('promotion.boost.remedy', ['boost_id' => $id, 'action' => $input['action'], 'reason' => $input['reason']], $staff->id, 'staff');

            return PromotionResource::boost(Reports::boosts([self::find($id)])[0]);
        }));
    }

    public static function update(object $boost, array $data): void
    {
        DB::table('promotion_boosts')->where('id', $boost->id)->update([
            ...$data, 'version' => $boost->version + 1, 'updated_at' => Billing::timestamp(),
        ]);
        $placement = array_intersect_key($data, array_flip(['status', 'starts_at', 'ends_at']));
        if ($placement) {
            DB::table('promotion_placements')->where('boost_id', $boost->id)->update([
                ...$placement, 'version' => DB::raw('version + 1'), 'updated_at' => Billing::timestamp(),
            ]);
        }
    }
}
