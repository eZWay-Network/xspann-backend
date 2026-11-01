<?php

// Navigation and command search share this permission-scoped catalog.
return [
    [
        'label' => 'Workspace',
        'placement' => 'main',
        'items' => [
            ['title' => 'Overview', 'route' => 'staff.dashboard', 'icon' => 'dashboard', 'ability' => 'dashboard.view'],
            ['title' => 'Attention', 'route' => 'staff.attention', 'icon' => 'bell', 'ability' => 'dashboard.view'],
        ]
    ],
    [
        'label' => 'Community', 'placement' => 'main',
        'items' => [[
            'title' => 'Community', 'icon' => 'users',
            'children' => [
                ['title' => 'Users', 'route' => 'staff.users.index', 'icon' => 'users', 'ability' => 'users.view'],
                ['title' => 'Artists', 'route' => 'staff.artists.index', 'icon' => 'artist', 'ability' => 'artists.view'],
            ],
        ]],
    ],
    [
        'label' => 'Content', 'placement' => 'main',
        'items' => [[
            'title' => 'Content', 'icon' => 'video',
            'children' => [
                ['title' => 'Videos', 'route' => 'staff.videos.index', 'icon' => 'video', 'ability' => 'content.view'],
                ['title' => 'Audio', 'route' => 'staff.audios.index', 'icon' => 'artist', 'ability' => 'content.view'],
                ['title' => 'Playlists', 'route' => 'staff.playlists.index', 'icon' => 'artist', 'ability' => 'content.view'],
            ],
        ]],
    ],
    [
        'label' => 'Moderation', 'placement' => 'main',
        'items' => [[
            'title' => 'Moderation', 'icon' => 'flag',
            'children' => [
                ['title' => 'Reports', 'route' => 'staff.reports.index', 'icon' => 'flag', 'ability' => 'reports.view'],
                ['title' => 'Appeals', 'route' => 'staff.appeals.index', 'icon' => 'shield', 'ability' => 'appeals.view'],
                ['title' => 'Rights review', 'route' => 'staff.rights', 'icon' => 'shield', 'ability' => 'reports.view'],
            ],
        ]],
    ],
    [
        'label' => 'Promotions', 'placement' => 'main',
        'items' => [[
            'title' => 'Promotions', 'icon' => 'megaphone',
            'children' => [
                ['title' => 'Boosts', 'route' => 'staff.boosts.index', 'icon' => 'megaphone', 'ability' => 'boosts.view'],
                ['title' => 'Placements', 'route' => 'staff.placements.index', 'icon' => 'clock', 'ability' => 'placements.view'],
                ['title' => 'Surface', 'route' => 'staff.surface', 'icon' => 'activity', 'ability' => 'promotion.surface.view'],
                ['title' => 'Credits', 'route' => 'staff.credits', 'icon' => 'activity', 'ability' => 'credits.view'],
            ],
        ]],
    ],
    [
        'label' => 'Billing', 'placement' => 'main',
        'items' => [[
            'title' => 'Billing', 'icon' => 'activity',
            'children' => [
                ['title' => 'Subscriptions', 'route' => 'staff.billing.subscriptions.index', 'icon' => 'activity', 'ability' => 'billing.view'],
                ['title' => 'Invoices', 'route' => 'staff.billing.invoices.index', 'icon' => 'activity', 'ability' => 'billing.view'],
                ['title' => 'Checkouts', 'route' => 'staff.billing.checkouts.index', 'icon' => 'activity', 'ability' => 'billing.view'],
                ['title' => 'Adjustments', 'route' => 'staff.billing.adjustments.index', 'icon' => 'activity', 'ability' => 'billing.view'],
                ['title' => 'Webhooks', 'route' => 'staff.billing.webhooks.index', 'icon' => 'activity', 'ability' => 'billing.view'],
                ['title' => 'Finance reports', 'route' => 'staff.billing.reports', 'icon' => 'activity', 'ability' => 'analytics.finance.view'],
            ],
        ]],
    ],
    [
        'label' => 'Operations', 'placement' => 'main',
        'items' => [[
            'title' => 'Operations', 'icon' => 'activity',
            'children' => [
                ['title' => 'Operations overview', 'route' => 'staff.operations.overview', 'icon' => 'activity', 'ability' => 'operations.view'],
                ['title' => 'Jobs & workers', 'route' => 'staff.operations.jobs', 'icon' => 'activity', 'ability' => 'operations.view'],
                ['title' => 'Media processing', 'route' => 'staff.media', 'icon' => 'video', 'ability' => 'operations.view'],
                ['title' => 'Releases', 'route' => 'staff.releases.index', 'icon' => 'clock', 'ability' => 'operations.view'],
                ['title' => 'Communications', 'route' => 'staff.communications', 'icon' => 'bell', 'ability' => 'communications.view'],
                ['title' => 'Audit history', 'route' => 'staff.audit', 'icon' => 'shield', 'ability' => 'audit.view'],
                ['title' => 'My exports', 'route' => 'staff.exports.index', 'icon' => 'activity', 'ability' => 'exports.create'],
                ['title' => 'Privacy requests', 'route' => 'staff.privacy.index', 'icon' => 'shield', 'ability' => 'privacy.view'],
            ],
        ]],
    ],
    [
        'label' => 'Account',
        'placement' => 'bottom',
        'items' => [
            [
                'title' => 'Settings',
                'icon' => 'settings',
                'children' => [
                    ['title' => 'My profile', 'route' => 'staff.profile', 'icon' => 'user'],
                    ['title' => 'Preferences', 'route' => 'staff.preferences', 'icon' => 'settings'],
                    ['title' => 'Security & sessions', 'route' => 'staff.security', 'icon' => 'shield'],
                    ['title' => 'Role permissions', 'route' => 'staff.settings.roles', 'icon' => 'shield', 'ability' => 'staff.view'],
                    ['title' => 'General', 'route' => 'staff.settings.general', 'icon' => 'settings', 'ability' => 'settings.view'],
                    ['title' => 'Catalog', 'route' => 'staff.settings.catalog', 'icon' => 'settings', 'ability' => 'settings.view'],
                    ['title' => 'Policies', 'route' => 'staff.settings.policies', 'icon' => 'settings', 'ability' => 'settings.view'],
                    ['title' => 'Taxonomy', 'route' => 'staff.settings.taxonomy', 'icon' => 'settings', 'ability' => 'settings.view'],
                    ['title' => 'Integrations', 'route' => 'staff.settings.integrations', 'icon' => 'settings', 'ability' => 'settings.view'],
                    ['title' => 'Team & access', 'route' => 'staff.team', 'icon' => 'users', 'ability' => 'staff.view'],
                ]
            ],
        ]
    ],
];
