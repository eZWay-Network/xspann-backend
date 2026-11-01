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
