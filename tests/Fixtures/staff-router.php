<?php

// Only the isolated browser fixture uses this router. Never expose it in production.
$configuration = getenv('XPN_STAFF_TEST_CONFIG');

if (!$configuration || !is_file($configuration)) {
    http_response_code(500);
    exit;
}
$root = dirname(__DIR__, 2);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (str_starts_with($path, '/build/') && is_file($root . '/public' . $path)) {
    return false;
}
require $root . '/vendor/autoload.php';
$config = json_decode(file_get_contents($configuration), true, flags: JSON_THROW_ON_ERROR);
$app = require $root . '/bootstrap/app.php';
$app->mergeConfig($config);
$app->withQueue();
$app->run();
