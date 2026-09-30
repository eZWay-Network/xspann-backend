<?php

// Dedicated HTTP fixture: its root, database and all storage are temporary.
$configuration = getenv('XSPANN_HTTP_TEST_CONFIG');
if (!$configuration || !is_file($configuration)) {
    http_response_code(500);
    exit;
}
require dirname(__DIR__, 2) . '/vendor/autoload.php';
$config = json_decode(file_get_contents($configuration), true, flags: JSON_THROW_ON_ERROR);
$root = dirname(__DIR__, 2);
$mediaRoot = realpath($config['storage']['disks']['public']['root']);
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$file = str_starts_with($path, '/uploads/') ? realpath($mediaRoot . '/' . substr($path, 9)) : false;
if ($mediaRoot && $file && str_starts_with($file, $mediaRoot . '/') && is_file($file)) {
    return false;
}
$app = \Spark\Foundation\Application::create(dirname($configuration), config: $config, providers: require "$root/bootstrap/providers.php")
    ->withMiddleware(load: "$root/bootstrap/middlewares.php", queue: ['cors'])
    ->withRouting(api: "$root/routes/api.php", web: "$root/routes/web.php");
try {
    $app->run();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['exception' => get_class($e), 'message' => $e->getMessage()]);
}
