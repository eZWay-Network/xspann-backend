<?php

// Add modules only when their actual browser workflows ship.
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
        'label' => 'Operations', 'placement' => 'main',
        'items' => [[
            'title' => 'Operations', 'icon' => 'activity',
            'children' => [
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
                    ['title' => 'Security & sessions', 'route' => 'staff.security', 'icon' => 'shield'],
                    ['title' => 'Team & access', 'route' => 'staff.team', 'icon' => 'users', 'ability' => 'staff.view'],
                ]
            ],
        ]
    ],
];
