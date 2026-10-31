<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$app->mergeConfig(json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR));
if (($argv[2] ?? '') === 'restart') {
    (new \Spark\Relay\Console\RestartCommand())->handle([]);
} else {
    (new \Spark\Relay\Console\StartCommand())->handle([]);
}
