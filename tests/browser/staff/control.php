<?php

require dirname(__DIR__, 3) . '/vendor/autoload.php';
$directory = $argv[1] ?? '';

if (!str_starts_with($directory, '/private/tmp/xpn-staff-') || !is_file($directory . '/config.json')) {
    throw new RuntimeException('Only isolated browser fixture databases are supported.');
}
$_ENV['APP_ENV'] = 'testing';
$app = require dirname(__DIR__, 3) . '/bootstrap/app.php';
$app->mergeConfig(json_decode(file_get_contents($directory . '/config.json'), true, flags: JSON_THROW_ON_ERROR));
$app->withQueue();
$action = $argv[2] ?? '';
$user = \App\Models\User::where('username', $argv[3] ?? 'admin')->firstOrFail();

if ($action === 'code') {
    $member = query('staff_accounts')->where('user_id', $user->id)->first();
    echo \App\Services\Totp::code(\Spark\Facades\Hash::decrypt($member->mfa_secret), intdiv(time(), 30));
} elseif ($action === 'reset-invitee' && $user->username === 'invitee') {
    // Reset only this synthetic fixture identity between invitation journeys.
    \App\Services\Staff\Sessions::revokeAll($user->id);
    query('staff_invitations')->where('user_id', $user->id)->delete();
    query('staff_accounts')->where('user_id', $user->id)->delete();
} elseif ($action === 'reset-replay') {
    // Test-only control: allows independent browser cases without waiting for wall time.
    query('staff_accounts')->where('user_id', $user->id)->update(['last_counter' => -1]);
    foreach (['login', 'challenge'] as $path) {
        $key = '5m:20:' . md5('POST|' . $path . '|127.0.0.1|staff-' . $path);
        \Spark\Cache\Cache::make('throttle')->forget($key);
    }
} elseif ($action === 'expire-mfa') {
    query('staff_sessions')->where('user_id', $user->id)->update(['mfa_expires_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
} elseif ($action === 'revoke') {
    \App\Services\Staff\Sessions::revokeAll($user->id);
} else {
    throw new RuntimeException('Unsupported fixture control.');
}
