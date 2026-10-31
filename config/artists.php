<?php

return [
    // Step 3 will resolve a paid plan from billing; HTTP input never selects a plan.
    'plans' => [
        'free' => [
            'links' => 1,
            'playlists' => 1,
            'featured_release' => false,
            'enhanced_profile' => false,
            'enhanced_directory_card' => false,
            'analytics_30_days' => false,
        ],
        'artist_starter' => [
            'links' => 5,
            'playlists' => 10,
            'featured_release' => true,
            'enhanced_profile' => true,
            'enhanced_directory_card' => false,
            'analytics_30_days' => true,
        ],
        'artist_pro' => [
            'links' => 5,
            'playlists' => 50,
            'featured_release' => true,
            'enhanced_profile' => true,
            'enhanced_directory_card' => true,
            'analytics_30_days' => true,
        ],
    ],
    'genres_per_artist' => 5,
    'videos_per_playlist' => 100,
    'playlist_categories' => ['release', 'live', 'behind_the_scenes', 'other'],
    'link_types' => ['website', 'music', 'merchandise', 'social'],
];
