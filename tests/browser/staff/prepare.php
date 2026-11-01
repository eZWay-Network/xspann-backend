<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$directory = $argv[1] ?? '/private/tmp/xpn-staff-browser';

if (!str_starts_with($directory, '/private/tmp/xpn-staff-')) {
    throw new RuntimeException('Browser fixtures require an isolated /private/tmp/xpn-staff-* directory.');
}

if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$_ENV['TEST_STORAGE_PATH'] = $directory;
$_ENV['APP_ENV'] = 'testing';
$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$config = require dirname(__DIR__, 2) . '/config.php';
$config['app']['url'] = $argv[2] ?? 'http://127.0.0.1:8765';
$config['app']['env'] = 'testing';
$config['database']['connections']['sqlite']['file'] = $directory . '/database.sqlite';
$app->mergeConfig($config);
$app->withQueue();

if (file_exists($directory . '/database.sqlite')) {
    throw new RuntimeException('Use a fresh fixture directory; existing fixture data is never reset.');
}

foreach (glob(dirname(__DIR__, 3) . '/database/migrations/migration_*.php') as $path) {
    (require $path)->up();
}
$secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

foreach ([
    'admin' => 'Alex Morgan',
    'moderator' => 'Jamie Rivera',
    'analyst' => 'Sam Chen',
    'support' => 'Casey Jordan',
    'finance' => 'Drew Taylor',
    'operations' => 'Robin Lee',
    'promotion_operator' => 'Morgan Blake',
] as $role => $name) {
    $user = \App\Models\User::create([
        'name' => $name, 'username' => $role, 'email' => "$role@example.test", 'password' => 'StaffFixturePassword123!',
        'email_verified_at' => now(), 'onboarded_at' => now(), 'date_of_birth' => '1990-01-01',
    ]);
    query('staff_accounts')->insert([
        'user_id' => $user->id, 'role' => $role, 'mfa_secret' => \Spark\Facades\Hash::encrypt($secret),
        'last_counter' => -1, 'enabled' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
}
\App\Models\User::create([
    'name' => 'Taylor Brooks', 'username' => 'invitee', 'email' => 'invitee@example.test', 'password' => 'StaffFixturePassword123!',
    'email_verified_at' => now(), 'onboarded_at' => now(), 'date_of_birth' => '1990-01-01',
]);
for ($index = 1; $index <= 48; $index++) {
    $date = gmdate('Y-m-d H:i:s', time() - (($index * 7) % 30) * 86400);
    $id = query('users')->insert([
        'name' => "Artist $index", 'username' => "artist_$index", 'email' => "artist_$index@example.test", 'status' => 'active',
        'email_verified_at' => $date, 'onboarded_at' => $date, 'created_at' => $date,
    ]);
    query('artists')->insert(['user_id' => $id, 'stage_name' => "Artist $index", 'slug' => "artist-$index", 'listed' => $index % 4 !== 0, 'data_since' => $date, 'created_at' => $date]);
}
file_put_contents($directory . '/config.json', json_encode($config, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo $directory . '/config.json' . PHP_EOL;
