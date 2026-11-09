<?php

/** Export the same registered-route contract as the web reference, without using .env or an application database. */
$_ENV['APP_ENV'] = 'testing';
require dirname(__DIR__) . '/vendor/autoload.php';

$temporary = sys_get_temp_dir() . '/xpn-reference-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$_ENV['TEST_STORAGE_PATH'] = $temporary;

try {
    $app = require dirname(__DIR__) . '/bootstrap/app.php';
    $app->mergeConfig(require dirname(__DIR__) . '/tests/config.php');
    $app->withQueue();
    $app->boot();
    $reference = \App\Http\Controllers\DocsController::reference('http://localhost:8080/api/v1');
    $reference['realtime'] = [
        'events' => \App\Services\Realtime::TYPES,
        'fields' => ['event_id', 'type', 'occurred_at', 'resource_id', 'version'],
        'optional_fields' => ['message.created' => ['conversation_id']],
        'example' => ['event_id' => '8142', 'type' => 'message.created', 'occurred_at' => '2026-11-08T10:00:00Z', 'resource_id' => 731, 'version' => 12, 'conversation_id' => 4],
        'recovery' => 'Subscribe to the token-bound private channel, then load REST state. Deduplicate event IDs and reload on reconnect/version gaps. Fetch message catch-up with after_id.',
    ];
    echo json_encode($reference, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} finally {
    \Spark\Utils\File::deleteDirectory($temporary);
}
