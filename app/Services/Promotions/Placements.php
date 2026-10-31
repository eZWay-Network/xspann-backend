<?php

namespace App\Services\Promotions;

use App\Models\{Artist, User};
use App\Services\AuditTrail;
use App\Services\Billing\Billing;
use Spark\Facades\DB;

class Placements
{
    public static function find(int $id, ?int $owner = null): object
    {
        $query = Promotion::table('placements')->where('id', $id);
        if ($owner !== null) {
            $query->where('user_id', $owner);
        }
        $row = $query->first();
        abort_unless((bool) $row, 404, 'Placement not found.');

        return $row;
    }

    /** Enrollment always reserves and records both monthly obligations, never just one benefit. */
    public static function create(User $staff, array $input): object
    {
        return Promotion::transaction(function () use ($staff, $input): object {
            $artist = Artist::findOrFail((int) $input['artist_id']);
            abort_unless(Promotion::artistEligible($artist->id), 422, 'Artist is not publicly eligible.');
            abort_unless($input['capacity_confirmed'] === true, 422, 'Confirm capacity for both monthly obligations.');
            $month = $input['month'];
            abort_unless(preg_match('/^20\d{2}-(?:0[1-9]|1[0-2])$/D', $month), 422, 'Month must be YYYY-MM.');
            $monthStart = "$month-01 00:00:00";
            $due = (new \DateTimeImmutable($monthStart, new \DateTimeZone('UTC')))->modify('+1 month')->format('Y-m-d H:i:s');
            abort_unless($due > Billing::timestamp() && $monthStart <= Billing::timestamp(time() + 366 * 86400), 422, 'Choose a current or upcoming pilot month.');
            $network = $input['network_account'];
            $accounts = config('promotions.network_accounts', []);
            abort_unless(isset($accounts[$network]) && (int) ($accounts[$network]['monthly_capacity'] ?? 0) > 0, 503, 'No confirmed capacity for this named network account.');
            abort_if(Promotion::table('enrollments')->where('artist_id', $artist->id)->where('month', $month)->exists(), 409, 'This artist already has both monthly obligations. Edit the existing records.');
            abort_unless(Promotion::table('enrollments')->where('month', $month)->count() < (int) config('promotions.pilot_monthly_capacity'), 409, 'Monthly pilot capacity is full or unconfirmed.');
            abort_unless(Promotion::table('enrollments')->where('month', $month)->where('network_account', $network)->count() < (int) $accounts[$network]['monthly_capacity'], 409, 'Monthly network capacity is full.');
            $enrollment = DB::table('promotion_enrollments')->insert([
                'artist_id' => $artist->id, 'user_id' => $artist->user_id, 'environment' => config('billing.environment'),
                'month' => $month, 'network_account' => $network, 'confirmed_by' => $staff->id, 'created_at' => Billing::timestamp(),
            ]);
            $selected = null;
            foreach (['feature', 'network_post'] as $type) {
                $id = DB::table('promotion_placements')->insert([
                    'eligibility_revision' => Invalidations::revision(), 'enrollment_id' => $enrollment, 'artist_id' => $artist->id, 'user_id' => $artist->user_id,
                    'environment' => config('billing.environment'), 'type' => $type, 'month' => $month,
                    'destination' => $type === 'feature' ? 'web_featured_artists' : $network,
                    'due_at' => $due, 'reason' => $input['reason'],
                    'created_at' => Billing::timestamp(), 'updated_at' => Billing::timestamp(),
                ]);
                if ($type === $input['type']) {
                    $selected = self::find($id);
                }
            }
            if (isset($input['starts_at'])) {
                self::schedule($selected, Promotion::at($input['starts_at']), Promotion::at($input['ends_at'] ?? ''), false);
            } else {
                abort_if(isset($input['ends_at']), 422, 'Supply both schedule dates.');
            }
            AuditTrail::record('promotion.pilot.enrolled', ['eligibility_revision' => Invalidations::revision(), 'enrollment_id' => $enrollment, 'reason' => $input['reason']], $staff->id, 'staff');

            return self::find($selected->id);
        });
    }

    private static function schedule(object $row, string $start, string $end, bool $extension): void
    {
        Promotion::future($start);
        abort_unless($end > $start, 422, 'End must follow start.');
        if (!$extension) {
            abort_unless($start >= "$row->month-01 00:00:00" && $end <= $row->due_at, 422, 'Schedule must fit the enrolled UTC month.');
        }
        if ($row->type === 'feature') {
            abort_unless(Promotion::seconds($end) - Promotion::seconds($start) === 7 * 86400, 422, 'A feature is exactly seven days.');
            self::featureCapacity($row->id, $start, $end);
        } else {
            abort_unless(Promotion::seconds($end) - Promotion::seconds($start) <= 86400, 422, 'Use a network post delivery window of at most one day.');
        }
        self::write($row, ['starts_at' => $start, 'ends_at' => $end, 'status' => 'scheduled', 'eligibility_revision' => Invalidations::revision()]);
    }

    private static function featureCapacity(int $exclude, string $start, string $end): void
    {
        // Sweep interval boundaries: overlapping records may be sequential, not simultaneous.
        $points = [[Promotion::seconds($start), 1], [Promotion::seconds($end), -1]];
        foreach (Promotion::table('placements')->where('type', 'feature')->where('id', '!=', $exclude)
            ->whereIn('status', ['scheduled', 'active'])->where('starts_at', '<', $end)->where('ends_at', '>', $start)->get() as $row) {
            $points[] = [max(Promotion::seconds($start), Promotion::seconds($row->starts_at)), 1];
            $points[] = [min(Promotion::seconds($end), Promotion::seconds($row->ends_at)), -1];
        }
        usort($points, fn($a, $b) => ($a[0] <=> $b[0]) ?: ($a[1] <=> $b[1]));
        $active = 0;
        foreach ($points as [, $delta]) {
            $active += $delta;
            abort_if($active > (int) config('promotions.feature_capacity'), 409, 'Featured artist capacity is full for this window.');
        }
    }

    public static function edit(User $staff, int $id, array $input): object
    {
        return Promotion::transaction(function () use ($staff, $id, $input): object {
            $row = self::find($id);
            Promotion::version($row, (int) $input['version']);
            abort_if($row->type === 'boost', 409, 'Use boost review and remedy endpoints.');
            abort_if(in_array($row->status, ['completed', 'cancelled'], true), 409, 'This obligation is terminal.');
            if ($input['action'] === 'cancel') {
                self::write($row, ['status' => 'cancelled', 'reason' => $input['reason'], 'extension_consent' => null]);
            } elseif ($input['action'] === 'interrupt') {
                self::write($row, ['status' => 'interrupted', 'reason' => $input['reason'], 'extension_consent' => null]);
            } else {
                $start = Promotion::at($input['starts_at'] ?? '');
                $end = Promotion::at($input['ends_at'] ?? '');
                abort_unless(Promotion::artistEligible($row->artist_id), 422, 'Artist is not publicly eligible.');
                if ($row->status === 'interrupted' || ($row->starts_at && $row->starts_at <= Billing::timestamp())) {
                    // A running/missed obligation cannot be silently moved by an operator.
                    Promotion::future($start);
                    abort_unless($end > $start && ($row->type !== 'feature' || Promotion::seconds($end) - Promotion::seconds($start) === 604800)
                        && ($row->type !== 'network_post' || Promotion::seconds($end) - Promotion::seconds($start) <= 86400), 422, 'Invalid replacement window.');
                    self::proposal($row, $start, $end, $input['reason']);
                } else {
                    self::schedule($row, $start, $end, false);
                    DB::table('promotion_placements')->where('id', $id)->update(['reason' => $input['reason']]);
                }
            }
            AuditTrail::record('promotion.placement.' . $input['action'], ['placement_id' => $id, 'reason' => $input['reason']], $staff->id, 'staff');

            return self::find($id);
        });
    }

    public static function proposal(object $row, string $start, string $end, string $reason): void
    {
        self::write($row, ['status' => 'interrupted', 'extension_starts_at' => $start, 'extension_ends_at' => $end,
            'extension_consent' => 'pending', 'consented_at' => null, 'reason' => $reason]);
    }

    public static function consent(User $owner, int $id, int $version, bool $accepted): object
    {
        return Promotion::transaction(function () use ($owner, $id, $version, $accepted): object {
            $row = self::find($id, $owner->id);
            Promotion::version($row, $version);
            abort_unless($row->status === 'interrupted' && $row->extension_consent === 'pending', 409, 'No pending replacement dates.');
            if ($accepted) {
                Promotion::future($row->extension_starts_at);
                if ($row->type === 'boost') {
                    $boost = Boosts::find($row->boost_id, $owner->id);
                    abort_if($boost->settled_at || $boost->status !== 'interrupted' || $boost->settle_after <= Billing::timestamp(), 409, 'The boost settlement window has closed.');
                } else {
                    abort_unless(Promotion::artistEligible($row->artist_id), 409, 'Artist is not publicly eligible.');
                    self::schedule($row, $row->extension_starts_at, $row->extension_ends_at, true);
                    $row = self::find($id);
                }
            }
            self::write($row, ['extension_consent' => $accepted ? 'accepted' : 'rejected', 'consented_at' => Billing::timestamp()]);
            AuditTrail::record('promotion.extension.consent', ['placement_id' => $id, 'proposal_version' => $version, 'accepted' => $accepted,
                'starts_at' => $row->extension_starts_at, 'ends_at' => $row->extension_ends_at], $owner->id);

            return self::find($id);
        });
    }

    public static function evidence(User $staff, int $id, array $input): object
    {
        return Promotion::transaction(function () use ($staff, $id, $input): object {
            $row = self::find($id);
            abort_if($row->type === 'boost', 422, 'Boost fulfillment comes from availability records.');
            $hash = Promotion::hash($input);
            $existing = DB::table('promotion_evidence')->where('placement_id', $id)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Delivery evidence is immutable.');

                return $row;
            }
            Promotion::version($row, (int) $input['version']);
            abort_unless(in_array($row->status, ['scheduled', 'active', 'awaiting_evidence'], true), 409, 'Schedule or resolve the interruption before recording delivery.');
            abort_unless(Promotion::artistEligible($row->artist_id) && !Promotion::table('placements')->where('id', $id)->whereExists(Invalidations::placementQuery())->exists(), 409, 'Resolve artist eligibility and delivery interruptions before completing the obligation.');
            $start = Promotion::at($input['actual_starts_at']);
            $end = Promotion::at($input['actual_ends_at']);
            abort_unless($start <= $end && $end <= Billing::timestamp() && $start >= $row->starts_at && $end <= $row->ends_at, 422, 'Actual delivery must be in the approved window and cannot be in the future.');
            if ($row->type === 'feature') {
                abort_unless(Promotion::seconds($end) - Promotion::seconds($start) === 604800, 422, 'Record the actual full seven-day feature window.');
            }
            $proof = $input['proof'];
            $host = parse_url($proof, PHP_URL_HOST);
            $validUrl = filter_var($proof, FILTER_VALIDATE_URL) && parse_url($proof, PHP_URL_SCHEME) === 'https'
                && !parse_url($proof, PHP_URL_USER) && in_array(parse_url($proof, PHP_URL_PORT), [null, 443], true) && in_array(strtolower((string) $host), config('promotions.proof_hosts', []), true);
            $validReference = preg_match('/^evidence:[a-zA-Z0-9][a-zA-Z0-9._\/-]{7,180}$/D', $proof);
            abort_unless($validUrl || $validReference, 422, 'Use an approved HTTPS proof host or an evidence: archive reference.');
            $canonical = $validUrl ? 'https://' . strtolower($host) . rtrim(parse_url($proof, PHP_URL_PATH) ?? '/', '/') : $proof;
            $proofHash = hash('sha256', $canonical);
            abort_if(DB::table('promotion_evidence')->where('proof_hash', $proofHash)->exists(), 409, 'This proof already completes another obligation.');
            DB::table('promotion_evidence')->insert([
                'placement_id' => $id, 'proof_hash' => $proofHash, 'proof' => $proof, 'request_hash' => $hash,
                'actual_starts_at' => $start, 'actual_ends_at' => $end, 'note' => $input['note'],
                'actor_id' => $staff->id, 'created_at' => Billing::timestamp(),
            ]);
            self::write($row, ['status' => 'completed']);
            AuditTrail::record('promotion.placement.delivered', ['placement_id' => $id], $staff->id, 'staff');

            return self::find($id);
        });
    }

    public static function tick(object $row): void
    {
        if ($row->type !== 'boost' && $row->status === 'planned' && $row->due_at <= Billing::timestamp()) {
            self::write($row, ['status' => 'interrupted', 'reason' => 'Monthly obligation was not scheduled by its due date.']);
            return;
        }
        if ($row->type === 'boost' || !in_array($row->status, ['scheduled', 'active'], true)) {
            return;
        }
        if (!Promotion::artistEligible($row->artist_id) || Promotion::table('placements')->where('id', $row->id)->whereExists(Invalidations::placementQuery())->exists()) {
            self::write($row, ['status' => 'interrupted', 'reason' => 'Artist is no longer publicly eligible.']);
        } elseif ($row->ends_at <= Billing::timestamp()) {
            self::write($row, ['status' => 'awaiting_evidence']);
        } elseif ($row->starts_at <= Billing::timestamp() && $row->status !== 'active') {
            self::write($row, ['status' => 'active']);
        }
    }

    private static function write(object $row, array $data): void
    {
        DB::table('promotion_placements')->where('id', $row->id)->update([
            ...$data, 'version' => $row->version + 1, 'updated_at' => Billing::timestamp(),
        ]);
    }
}
