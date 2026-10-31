<?php

namespace App\Http\Resources;

use App\Services\Billing\Billing;

/** Explicit owner/staff representations; no payment identifiers or private contact fields. */
class PromotionResource
{
    public static function boost(object $row): array
    {
        return [
            'id' => (int) $row->id, 'artist_id' => $row->artist_id, 'video_id' => $row->video_id,
            'offer_id' => $row->offer_id, 'surface' => $row->surface, 'status' => $row->status,
            'version' => (int) $row->version, 'price_cents' => (int) $row->price_cents, 'currency' => 'usd',
            'duration_seconds' => (int) $row->duration_seconds, 'starts_at' => Billing::date($row->starts_at),
            'ends_at' => Billing::date($row->ends_at), 'activated_at' => Billing::date($row->activated_at),
            'rights_version' => $row->rights_version, 'rights_confirmed_at' => Billing::date($row->rights_confirmed_at),
            'staff_hold' => (bool) $row->staff_hold, 'stop_reason' => $row->stop_reason,
            'settle_after' => Billing::date($row->settle_after), 'settled_at' => Billing::date($row->settled_at),
            'report' => $row->report, 'created_at' => Billing::date($row->created_at),
        ];
    }

    public static function placement(object $row): array
    {
        return [
            'id' => (int) $row->id, 'artist_id' => $row->artist_id, 'boost_id' => $row->boost_id,
            'type' => $row->type, 'month' => $row->month, 'destination' => $row->destination,
            'status' => $row->status, 'version' => (int) $row->version,
            'starts_at' => Billing::date($row->starts_at), 'ends_at' => Billing::date($row->ends_at),
            'due_at' => Billing::date($row->due_at), 'reason' => $row->reason,
            'extension' => ['starts_at' => Billing::date($row->extension_starts_at), 'ends_at' => Billing::date($row->extension_ends_at),
                'consent' => $row->extension_consent, 'consented_at' => Billing::date($row->consented_at)],
            'evidence' => $row->evidence ?? null, 'created_at' => Billing::date($row->created_at),
        ];
    }

    public static function ledger(object $row): array
    {
        return ['id' => (int) $row->id, 'boost_id' => $row->boost_id, 'kind' => $row->kind,
            'available_delta_cents' => (int) $row->available_delta, 'reserved_delta_cents' => (int) $row->reserved_delta,
            'used_delta_cents' => (int) $row->used_delta, 'currency' => 'usd', 'created_at' => Billing::date($row->created_at)];
    }

    public static function observation(object $row): array
    {
        return ['id' => (int) $row->id, 'surface' => $row->surface, 'available' => (bool) $row->available,
            'starts_at' => Billing::date($row->starts_at), 'ends_at' => Billing::date($row->ends_at),
            'reference' => $row->reference, 'note' => $row->note, 'created_at' => Billing::date($row->created_at)];
    }
}
