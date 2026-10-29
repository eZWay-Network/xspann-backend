<?php

$website = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');

return [
    // Publish the matching text at these URLs before accepting production enrollment.
    'documents' => [
        'terms' => ['version' => '2026-10-29', 'url' => "$website/terms", 'required' => true],
        'privacy' => ['version' => '2026-10-29', 'url' => "$website/privacy", 'required' => true],
        'community' => ['version' => '2026-10-29', 'url' => "$website/community", 'required' => true],
        'marketing' => ['version' => '2026-10-29', 'url' => "$website/marketing-preferences", 'required' => false],
    ],
    'rights_version' => '2026-10-29',
    'minimum_age' => 18,
    'export_hours' => 24,
    'closed_report_retention_days' => 365,
    'audit_retention_days' => 365,
    'completed_privacy_retention_days' => 30,
];
