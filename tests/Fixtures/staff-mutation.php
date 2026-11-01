<?php

// CLI-only worker for a genuine two-process race against a disposable test DB.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$path = $argv[1] ?? '';
$root = realpath(dirname(__DIR__, 2) . '/storage/framework/testing');
$real = realpath($path);

if (!$real || !$root || !str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Staff race workers require an isolated test configuration.');
}
$config = json_decode(file_get_contents($real), true, flags: JSON_THROW_ON_ERROR);
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->mergeConfig($config);
$app->withQueue();
$user = \App\Models\User::findOrFail((int) $argv[2]);
$member = query('staff_accounts')->where('user_id', $user->id)->first();
$session = query('staff_sessions')->where('user_id', $user->id)->first();
$context = new \App\Services\Staff\Context($user, $member, $session);

try {
    \App\Services\Staff\Team::update($context, $member->id, [
        'version' => 1,
        'action' => 'disable',
        'reason' => 'Concurrent departure test',
    ]);
    echo '200';
} catch (\App\Services\Staff\StaffException $exception) {
    echo $exception->status;
}
