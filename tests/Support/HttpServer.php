<?php

namespace Tests\Support;

use Spark\Http\Response;
use Spark\Testing\TestResponse;

/** A temporary localhost server for genuine HTTP uploads and S3 transport fixtures. */
final class HttpServer
{
    public readonly string $url;
    private mixed $process = null;

    public function __construct(string $router, string $directory, array $environment = [], ?string $documentRoot = null)
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) {
            throw new \RuntimeException($error);
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->url = 'http://' . $address;
        $log = $directory . '/server-' . basename($router) . '.log';
        $command = [PHP_BINARY, '-d', 'upload_max_filesize=520M', '-d', 'post_max_size=525M', '-S', $address];
        if ($documentRoot) {
            array_push($command, '-t', $documentRoot);
        }
        $command[] = $router;
        $this->process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, null, [...getenv(), ...$environment]);
        if (!is_resource($this->process)) {
            throw new \RuntimeException('Cannot start HTTP fixture.');
        }
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $ready = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
            if ($ready) {
                fclose($ready);
                return;
            }
            usleep(20000);
        }
        $this->stop();
        throw new \RuntimeException('HTTP fixture failed: ' . file_get_contents($log));
    }

    public function request(string $method, string $path, array|string|null $body = null, array $headers = []): TestResponse
    {
        $curl = curl_init($this->url . $path);
        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => array_map(fn($name, $value) => "$name: $value", array_keys($headers), $headers),
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[trim($name)] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $content = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($content === false) {
            throw new \RuntimeException($error);
        }
        return new TestResponse(new Response($content, $status, $responseHeaders));
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}
