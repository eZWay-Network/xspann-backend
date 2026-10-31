<?php

use App\Services\VideoRetry;
use App\Jobs\PruneUploads;
use Spark\Console\Prompt;

command('greet', function (Prompt $prompt) {
    $name = $prompt->ask('What is your name?');
    $prompt->message("Hello, {$name}!", 'success');
})->description('Show a Greeting Message');

command('videos:retry', function (Prompt $prompt, array $args) {
    $id = null;
    if (array_key_exists('id', $args)) {
        $id = is_string($args['id'])
            ? filter_var($args['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        if ($id === false) {
            throw new InvalidArgumentException('The --id option must be a positive video ID.');
        }
    }

    $prompt->message(
        VideoRetry::retry($id) ?
        'Failed videos have been queued for retry.' : 'No failed or interrupted videos found.'
    );
})->description('Retry failed or interrupted videos, optionally using --id');

command('uploads:prune', function (Prompt $prompt) {
    PruneUploads::dispatch();
    $prompt->message('Abandoned upload cleanup queued.');
})->description('Queue cleanup of expired, unreferenced uploads and chunk sessions');

command('audios:retry', function (Prompt $prompt, array $args): void {
    $id = null;
    if (array_key_exists('id', $args)) {
        $id = is_string($args['id'])
            ? filter_var($args['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($id === false) {
            throw new InvalidArgumentException('The --id option must be a positive audio ID.');
        }
    }

    $prompt->message(\App\Services\AudioRetry::retry($id)
        ? 'Failed or interrupted audio uploads queued.'
        : 'No eligible audio uploads found.');
})->description('Retry eligible failed or interrupted audio uploads, optionally using --id');

command('staff:enroll', function (Prompt $prompt, array $args): void {
    $id = filter_var($args['user'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $role = $args['role'] ?? 'moderator';
    if (!$id || !in_array($role, ['moderator', 'admin'], true)) {
        throw new InvalidArgumentException('Use --user=<id> and --role=moderator|admin.');
    }
    $user = \App\Models\User::findOrFail($id);
    $secret = \App\Services\Totp::secret();
    $prompt->message("Enroll or reset MFA for $user->username ($role). This replaces existing staff MFA.");
    $prompt->message("Authenticator: XPN Social / $user->username; SHA1; 6 digits; 30 seconds; secret: $secret");
    $code = $prompt->ask('Enter the six-digit authenticator code to confirm enrollment');
    \App\Services\AccountSecurity::enrollStaff($user, $role, $secret, $code);
    $prompt->message('Staff enrolled. Previous MFA sessions have been revoked. Wait for the next code before signing in.', 'success');
})->description('Enroll or recover staff MFA using a trusted operator terminal');

command('staff:revoke', function (Prompt $prompt, array $args): void {
    $id = filter_var($args['user'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        throw new InvalidArgumentException('Use --user=<id>.');
    }
    \App\Services\AccountSecurity::revokeStaff($id);
    $prompt->message('Staff access revoked.');
})->description('Revoke staff access and recent MFA sessions');

command('accounts:maintenance', function (Prompt $prompt): void {
    (new \App\Jobs\FoundationMaintenance())->handle();
    $prompt->message('Account recovery, expiry and retention maintenance completed.');
})->description('Recover durable privacy work and clean expired private data');

command('accounts:retry', function (Prompt $prompt, array $args): void {
    $id = filter_var($args['request'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        throw new InvalidArgumentException('Use --request=<privacy-request-id>.');
    }
    $updated = query('privacy_requests')->where('id', $id)->where('status', 'failed')
        ->update(['status' => 'pending', 'attempts' => 0, 'error_code' => null, 'updated_at' => now()]);
    if ($updated) {
        \App\Services\AuditTrail::record('privacy.retry_requested', ['request_id' => $id], null, 'console');
        \App\Services\PrivacyRequests::dispatch($id);
    }
    $prompt->message($updated ? 'Privacy request queued again.' : 'No failed request found.');
})->description('Retry a failed privacy request after correcting its underlying storage or database problem');
