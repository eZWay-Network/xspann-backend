<?php

// Local transport fixture, not an AWS authorization emulator.
$root = getenv('XSPANN_S3_TEST_ROOT');
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (!$root || !str_starts_with($path, '/example/') || str_contains($path, '..')) {
    http_response_code(404);
    exit;
}
if (!isset($_GET['X-Amz-Signature']) && !str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'AWS4-HMAC-SHA256 ')) {
    http_response_code(403);
    exit;
}
$key = substr($path, strlen('/example/'));
$file = "$root/$key";
file_put_contents("$root/requests.log", $_SERVER['REQUEST_METHOD'] . " $key\n", FILE_APPEND);

switch ($_SERVER['REQUEST_METHOD']) {
    case 'PUT':
        if (is_file("$root/fail-put")) {
            http_response_code(503);
            break;
        }
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0700, true);
        }
        $source = fopen('php://input', 'rb');
        $destination = fopen($file, 'wb');
        stream_copy_to_stream($source, $destination);
        fclose($source);
        fclose($destination);
        break;
    case 'GET':
    case 'HEAD':
        if (!is_file($file)) {
            http_response_code(404);
            break;
        }
        header('Content-Type: ' . mime_content_type($file));
        header('Content-Length: ' . filesize($file));
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($file)) . ' GMT');
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            readfile($file);
        }
        break;
    case 'DELETE':
        if (is_file("$root/fail-delete")) {
            http_response_code(503);
            break;
        }
        if (is_file($file)) {
            unlink($file);
        }
        http_response_code(204);
        break;
    default:
        http_response_code(405);
}
