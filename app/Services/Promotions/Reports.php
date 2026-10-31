<?php

namespace App\Services\Promotions;

use App\Services\Billing\Billing;
use Spark\Facades\DB;

class Reports
{
    public static function boosts(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_column($rows, 'id');
        $cutoffs = Invalidations::cutoffs($rows);
        $reservations = DB::table('promotion_reservations')->whereIn('boost_id', $ids)->get();
        $deliveries = DB::table('promotion_delivery')->whereIn('boost_id', $ids)->get();
        $outages = [];
        if (count($deliveries)) {
            $starts = array_column($deliveries->all(), 'starts_at');
            $ends = array_column($deliveries->all(), 'ends_at');
            $outages = Availability::windows(config('promotions.surface'), min($starts), max($ends), false);
        }
        $events = DB::table('promotion_events')->whereIn('boost_id', $ids)->select(['boost_id', 'kind'])
            ->selectRaw('COUNT(*) AS total')->groupBy(['boost_id', 'kind'])->get();
        foreach ($rows as $row) {
            $report = ['reserved_cents' => 0, 'charged_cents' => 0, 'returned_cents' => 0,
                'delivered_seconds' => 0, 'undelivered_seconds' => (int) $row->duration_seconds,
                'render_events' => 0, 'click_events' => 0, 'telemetry_is_billable' => false];
            foreach ($reservations as $reservation) {
                if ($reservation->boost_id !== $row->id) {
                    continue;
                }
                $report['reserved_cents'] += $reservation->status === 'reserved' ? $reservation->amount_cents : 0;
                $report['charged_cents'] += $reservation->status === 'charged' ? $reservation->amount_cents : 0;
                $report['returned_cents'] += $reservation->returned_cents;
            }
            $windows = [];
            foreach ($deliveries as $delivery) {
                if ($delivery->boost_id === $row->id) {
                    $windows[] = [Promotion::seconds($delivery->starts_at), Promotion::seconds($delivery->ends_at)];
                }
            }
            if ($cutoff = ($cutoffs[$row->id] ?? null)) {
                $windows = Availability::subtract($windows, [[Promotion::seconds($cutoff), PHP_INT_MAX]]);
            }
            foreach (Availability::subtract(Availability::merge($windows), $outages) as [$start, $end]) {
                $report['delivered_seconds'] += $end - $start;
            }
            $report['delivered_seconds'] = min((int) $row->duration_seconds, $report['delivered_seconds']);
            $report['undelivered_seconds'] -= $report['delivered_seconds'];
            foreach ($events as $event) {
                if ($event->boost_id === $row->id) {
                    $report[$event->kind . '_events'] = (int) $event->total;
                }
            }
            $row->report = $report;
        }

        return $rows;
    }

    public static function placements(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $proofs = DB::table('promotion_evidence')->whereIn('placement_id', array_column($rows, 'id'))->get()->keyBy('placement_id');
        foreach ($rows as $row) {
            $proof = $proofs[$row->id] ?? null;
            $row->evidence = $proof ? ['proof' => $proof->proof, 'actual_starts_at' => Billing::date($proof->actual_starts_at),
                'actual_ends_at' => Billing::date($proof->actual_ends_at), 'note' => $proof->note, 'recorded_at' => Billing::date($proof->created_at)] : null;
        }

        return $rows;
    }
}
