<?php

namespace Tests\Support;

/** Isolated real daemon and a minimal Pusher protocol 7 WebSocket client. */
class RelayServer
{
    private mixed $process = null;
    public int $port;
    private string $configPath;
    private string $logPath;

    public function __construct(string $directory, array $config)
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (!$socket) {
            throw new \RuntimeException($error);
        }
        $this->port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $config['relay']['server'] = ['host' => '127.0.0.1', 'port' => $this->port, 'restart_file' => $directory . '/relay.restart'];
        $this->configPath = $directory . '/relay-fixture.json';
        $this->logPath = $directory . '/relay-fixture.log';
        file_put_contents($this->configPath, json_encode($config, JSON_THROW_ON_ERROR));
        $this->start();
    }

    public function start(): void
    {
        $this->process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Fixtures/relay-server.php', $this->configPath],
            [0 => ['pipe', 'r'], 1 => ['file', $this->logPath, 'a'], 2 => ['file', $this->logPath, 'a']], $pipes);
        fclose($pipes[0]);
        for ($i = 0; $i < 100; $i++) {
            $socket = @stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 0.05);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(20000);
        }
        throw new \RuntimeException('Relay fixture failed: ' . file_get_contents($this->logPath));
    }

    public function restart(): void
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Fixtures/relay-server.php', $this->configPath, 'restart'],
            [0 => ['pipe', 'r'], 1 => ['file', $this->logPath, 'a'], 2 => ['file', $this->logPath, 'a']], $pipes);
        fclose($pipes[0]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('Restart command failed.');
        }
        for ($i = 0; $i < 200; $i++) {
            if (!proc_get_status($this->process)['running']) {
                proc_close($this->process);
                $this->process = null;
                $this->start();
                return;
            }
            usleep(20000);
        }
        throw new \RuntimeException('Relay did not stop for restart.');
    }

    public function connect(): mixed
    {
        $socket = stream_socket_client('tcp://127.0.0.1:' . $this->port, $errno, $error, 2);
        if (!$socket) {
            throw new \RuntimeException($error);
        }
        stream_set_timeout($socket, 3);
        fwrite($socket, "GET /app/test-key?protocol=7&client=php-test&version=1.0 HTTP/1.1\r\nHost: 127.0.0.1\r\nOrigin: http://localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n\r\n");
        $headers = '';
        while (!str_ends_with($headers, "\r\n\r\n")) {
            $headers .= $this->bytes($socket, 1);
        }
        if (!str_contains($headers, '101 Switching Protocols')) {
            throw new \RuntimeException('WebSocket handshake failed: ' . $headers);
        }

        return $socket;
    }

    public function send(mixed $socket, array $event): void
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $size = strlen($payload);
        $header = chr(0x81) . ($size < 126 ? chr(0x80 | $size) : chr(0xfe) . pack('n', $size));
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $size; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        fwrite($socket, $header . $mask . $masked);
    }

    public function receive(mixed $socket): array
    {
        $header = $this->bytes($socket, 2);
        $length = ord($header[1]) & 127;
        if ($length === 126) {
            $length = unpack('n', $this->bytes($socket, 2))[1];
        }
        if ($length === 127 || (ord($header[0]) & 15) !== 1) {
            throw new \RuntimeException('Unexpected WebSocket frame.');
        }

        return json_decode($this->bytes($socket, $length), true, flags: JSON_THROW_ON_ERROR);
    }

    private function bytes(mixed $socket, int $length): string
    {
        $result = '';
        while (strlen($result) < $length) {
            $chunk = fread($socket, $length - strlen($result));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('WebSocket closed or timed out.');
            }
            $result .= $chunk;
        }

        return $result;
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
