<?php

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->mergeConfig(json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR));
$app->boot();
$queue = app(\Spark\Queue\Queue::class);
(require $root . '/bootstrap/jobs.php')($queue);
$queue->work(timeout: 1800, sleep: 1, queue: 'realtime');
