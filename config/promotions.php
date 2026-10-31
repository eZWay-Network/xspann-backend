<?php

return [
    // Enable only after the actual web surface and its availability monitor are verified.
    'bookings_enabled' => env('PROMOTION_BOOKINGS_ENABLED', false),
    'surface_url' => env('PROMOTION_SURFACE_URL'),
    'surface' => 'web_promoted_videos',
    'offer_id' => 'boost-24h-usd-500-v1',
    'price_cents' => 500,
    'duration_seconds' => 86400,
    'capacity' => 5,
    'rights_version' => 'advertising-2026-10-31',
    'availability_lease_seconds' => 120,
    'remedy_grace_seconds' => 86400,
    'booking_horizon_days' => 90,
    'event_retention_days' => 30,
    // Explicit deployment capacity and named accounts; the complimentary pilot starts closed.
    'pilot_monthly_capacity' => 0,
    'feature_capacity' => 1,
    'network_accounts' => [], // e.g. 'ezway-instagram' => ['name' => 'eZWay Instagram', 'monthly_capacity' => 4]
    'proof_hosts' => [], // Exact approved HTTPS hostnames; no wildcard or arbitrary outbound fetching.
];
