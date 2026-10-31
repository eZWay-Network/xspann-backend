<?php

use App\Services\VideoRetry;
use App\Jobs\PruneUploads;
use Spark\Console\Prompt;

command('artists:statistics', function (Prompt $prompt): void {
    \App\Services\ArtistStatistics::collectAll();
    $prompt->message('Current UTC artist statistics snapshots collected.');
})->description('Collect artist totals without fabricating historical daily data');

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


command('releases:publish', function (Prompt $prompt): void {
    (new \App\Jobs\PublishReleases())->handle();
    $prompt->message('Due releases and scheduling access checked.');
})->description('Publish eligible due releases and pause schedules after access ends');

command('billing:reconcile', function (Prompt $prompt): void {
    (new \App\Jobs\BillingMaintenance())->handle();
    $prompt->message('Billing inbox recovery and reconciliation completed.');
})->description('Recover pending provider events and reconcile initiated purchases');

command('billing:retry', function (Prompt $prompt, array $args): void {
    $id = filter_var($args['event'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        throw new InvalidArgumentException('Use --event=<local-inbox-id>.');
    }
    app(\App\Services\Billing\Webhooks::class)->process($id);
    $prompt->message('Billing event reconciled.');
})->description('Retry a billing inbox record after resolving its underlying problem');

command('billing:allocate', function (Prompt $prompt, array $args): void {
    $id = filter_var($args['adjustment'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        throw new InvalidArgumentException('Use --adjustment=<id> --allocation=full_period|no_service_change|released --reason=<review reason>.');
    }
    \App\Services\Billing\Adjustments::allocate($id, $args['allocation'] ?? '', $args['reason'] ?? '');
    $prompt->message('Adjustment allocation recorded and audited.');
})->description('Record an explicit refund allocation or release a reviewed dispute hold');


command('communications:deliver', function (Prompt $prompt): void {
    (new \App\Jobs\DeliverAnnouncements())->handle();
    $prompt->message('Pending announcement recipients checked.');
})->description('Recover durable announcement deliveries');

command('realtime:publish', function (Prompt $prompt): void {
    (new \App\Jobs\PublishRealtime())->handle();
    $prompt->message('Pending realtime hints checked.');
})->description('Publish committed outbox hints, retaining failures for retry');


command('promotions:maintain', function (Prompt $prompt): void {
    (new \App\Jobs\PromotionMaintenance())->handle();
    $prompt->message('Promotion credit, inventory, delivery and obligation recovery completed.');
})->description('Reconcile monthly credits and promotion delivery without inventing availability');

command('promotions:surface', function (Prompt $prompt, array $args): void {
    if (!in_array($args['available'] ?? null, ['0', '1'], true)
        || !is_string($args['reference'] ?? null) || strlen($args['reference']) < 8 || strlen($args['reference']) > 200
        || !is_string($args['note'] ?? null) || strlen(trim($args['note'])) < 8 || strlen($args['note']) > 1000) {
        throw new InvalidArgumentException('Use --available=0|1 --reference=<unique-observation> --note=<verification or incident details>.');
    }
    $input = ['available' => $args['available'] === '1', 'reference' => $args['reference'], 'note' => $args['note']];
    foreach (['starts_at', 'ends_at'] as $key) {
        if (isset($args[$key])) {
            $input[$key] = $args[$key];
        }
    }
    \App\Services\Promotions\Availability::record($input);
    $prompt->message('Authoritative surface observation recorded. Positive observations expire after 120 seconds.');
})->description('Record verified web-surface availability or a bounded incident from a trusted operator/monitor');
