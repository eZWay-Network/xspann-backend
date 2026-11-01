<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';
$directory = $argv[1] ?? '';
if (!str_starts_with($directory, '/private/tmp/xpn-staff-') || !is_file($directory . '/config.json')) {
    throw new RuntimeException('Use an isolated browser fixture.');
}
$_ENV['APP_ENV'] = 'testing';
$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->mergeConfig(json_decode(file_get_contents($directory . '/config.json'), true));
$app->withQueue();
$suffix = bin2hex(random_bytes(5));
$owner = \App\Models\User::create(['name' => 'Studio Artist', 'username' => 'studio_' . $suffix, 'email' => $suffix . '@example.test',
    'password' => 'password123', 'email_verified_at' => now(), 'onboarded_at' => now(), 'date_of_birth' => '1990-01-01']);
$fan = \App\Models\User::create(['name' => 'Listener', 'username' => 'listener_' . $suffix, 'email' => 'fan_' . $suffix . '@example.test',
    'email_verified_at' => now(), 'onboarded_at' => now()]);
$artist = \App\Models\Artist::create(['user_id' => $owner->id, 'stage_name' => 'Studio Artist', 'slug' => 'studio-' . $suffix, 'listed' => true, 'data_since' => now()]);
$video = \App\Models\Video::create(['user_id' => $owner->id, 'caption' => 'A studio performance', 'storage_path' => 'videos/' . $owner->id . '/fixture.mp4',
    'status' => 'published', 'processing_state' => 'ready', 'publication_state' => 'published', 'visibility' => 'public',
    'rights_version' => config('policies.rights_version'), 'rights_confirmed_at' => now()]);
$report = \App\Services\Moderation::report($fan, ['video_id' => $video->id, 'reason' => 'Review rights declaration', 'details' => 'Reported context visible only after opening evidence']);
$playlist = \App\Models\ArtistPlaylist::create(['artist_id' => $artist->id, 'title' => 'Studio playlist', 'visibility' => 'public', 'category' => 'release']);
query('artist_playlist_items')->insert(['playlist_id' => $playlist->id, 'video_id' => $video->id, 'position' => 1]);
$second = \App\Models\Video::create(['user_id' => $owner->id, 'caption' => 'A second performance', 'storage_path' => 'videos/' . $owner->id . '/second.mp4',
    'status' => 'published', 'processing_state' => 'ready', 'publication_state' => 'published', 'visibility' => 'public']);
query('artist_playlist_items')->insert(['playlist_id' => $playlist->id, 'video_id' => $second->id, 'position' => 2]);
// Synthetic historical metadata for browser presentation checks; no external billing or delivery.
$timestamp = gmdate('Y-m-d H:i:s');
$boost = query('promotion_boosts')->insert([
    'user_id' => $owner->id, 'artist_id' => $artist->id, 'video_id' => $video->id, 'environment' => 'test',
    'offer_id' => config('promotions.offer_id'), 'surface' => config('promotions.surface'), 'price_cents' => 500, 'duration_seconds' => 86400,
    'status' => 'interrupted', 'rights_version' => config('promotions.rights_version'), 'rights_confirmed_at' => $timestamp,
    'starts_at' => gmdate('Y-m-d H:i:s', time() - 86400), 'ends_at' => $timestamp, 'created_at' => $timestamp, 'updated_at' => $timestamp,
]);
foreach ([120, 60] as $seconds) {
    query('promotion_delivery')->insert(['boost_id' => $boost, 'starts_at' => gmdate('Y-m-d H:i:s', time() - $seconds), 'ends_at' => gmdate('Y-m-d H:i:s', time() - $seconds + 30)]);
}
echo json_encode(['report' => $report->id, 'user' => $owner->id, 'video' => $video->id, 'artist' => $artist->id, 'playlist' => $playlist->id, 'boost' => $boost]);
