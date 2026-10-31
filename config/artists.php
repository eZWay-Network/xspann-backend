<?php

return [
    // HTTP input never selects access; verified paid periods select these limits.
    'plans' => [
        'free' => [
            'links' => 1,
            'playlists' => 1,
            'featured_release' => false,
            'enhanced_profile' => false,
            'enhanced_directory_card' => false,
            'analytics_30_days' => false,
            'release_scheduling' => false,
            'fan_announcements' => 0,
            'announcement_recipients' => 0,
            'monthly_boost_credit_cents' => 0,
        ],
        'artist_starter' => [
            'links' => 5,
            'playlists' => 10,
            'featured_release' => true,
            'enhanced_profile' => true,
            'enhanced_directory_card' => false,
            'analytics_30_days' => true,
            'release_scheduling' => false,
            'fan_announcements' => 0,
            'announcement_recipients' => 0,
            'monthly_boost_credit_cents' => 0,
        ],
        'artist_pro' => [
            'links' => 5,
            'playlists' => 50,
            'featured_release' => true,
            'enhanced_profile' => true,
            'enhanced_directory_card' => true,
            'analytics_30_days' => true,
            'release_scheduling' => true,
            'fan_announcements' => 4,
            'announcement_recipients' => 1000,
            'monthly_boost_credit_cents' => 500,
        ],
    ],
    'genres_per_artist' => 5,
    'videos_per_playlist' => 100,
    'playlist_categories' => ['release', 'live', 'behind_the_scenes', 'other'],
    'link_types' => ['website', 'music', 'merchandise', 'social'],
];
