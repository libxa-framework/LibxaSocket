<?php

declare(strict_types=1);

/**
 * A real socket, a real handshake, a real publish.
 *
 * The unit tests drive the protocol directly: they prove the messages are
 * right and nothing at all about the transport. This starts the server on a
 * port, performs an RFC6455 handshake by hand, subscribes to a signed private
 * channel, publishes through the HTTP API, and checks the event arrives — so a
 * broken negotiator, a wrong mask expectation or a mangled frame codec fails
 * here rather than the first time somebody opens a browser.
 *
 * Run directly: php tests/integration/round-trip.php
 * Exits non-zero on the first failure.
 */

require __DIR__ . '/../../vendor/autoload.php';

use LibxaSocket\Application;
use LibxaSocket\Auth\ChannelAuthenticator;
use LibxaSocket\Server\RequestSignature;

const HOST = '127.0.0.1';
const PORT = 8123;

$app = new Application('demo', 'demo-key', 'demo-secret');
$failures = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $failures;

    if ($passed) {
        echo "  ok    {$what}\n";

        return;
    }

    $failures++;
    echo "  FAIL  {$what}" . ($detail === '' ? '' : " — {$detail}") . "\n";
}

/** A masked client text frame, which is what the specification requires. */
function frame(string $payload): string
{
    $mask = random_bytes(4);
    $length = strlen($payload);

    $header = chr(0x81) . chr(0x80 | ($length < 126 ? $length : 126));

    if ($length >= 126) {
        $header .= pack('n', $length);
    }

    $masked = '';

    for ($i = 0; $i < $length; $i++) {
        $masked .= $payload[$i] ^ $mask[$i % 4];
    }

    return $header . $mask . $masked;
}

/** @return list<string> */
function readFrames(string &$buffer): array
{
    $messages = [];

    while (strlen($buffer) >= 2) {
        $length = ord($buffer[1]) & 0x7F;
        $header = 2;

        if ($length === 126) {
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $header = 4;
        } elseif ($length === 127) {
            $length = unpack('J', substr($buffer, 2, 8))[1];
            $header = 10;
        }

        if (strlen($buffer) < $header + $length) {
            break;
        }

        $messages[] = substr($buffer, $header, $length);
        $buffer = substr($buffer, $header + $length);
    }

    return $messages;
}

// ── start the server ─────────────────────────────────────────────────────

$server = __DIR__ . '/server.php';

$process = proc_open(
    [PHP_BINARY, $server, (string) PORT],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);

if (! is_resource($process)) {
    exit("Could not start the server.\n");
}

$stop = static function () use ($process, $pipes): void {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    proc_terminate($process);
    proc_close($process);
};

// Wait for the listener rather than sleeping a guessed amount.
$ready = false;

for ($attempt = 0; $attempt < 100; $attempt++) {
    $probe = @stream_socket_client('tcp://' . HOST . ':' . PORT, $errno, $error, 0.2);

    if ($probe !== false) {
        fclose($probe);
        $ready = true;
        break;
    }

    usleep(100_000);
}

if (! $ready) {
    $stop();
    exit("The server never started listening on " . PORT . ".\n");
}

echo "server up on " . HOST . ':' . PORT . "\n";

// ── handshake ────────────────────────────────────────────────────────────

$socket = stream_socket_client('tcp://' . HOST . ':' . PORT, $errno, $error, 5);
stream_set_blocking($socket, false);

fwrite($socket, implode("\r\n", [
    'GET /app/demo-key?protocol=7&client=integration HTTP/1.1',
    'Host: ' . HOST . ':' . PORT,
    'Upgrade: websocket',
    'Connection: Upgrade',
    'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)),
    'Sec-WebSocket-Version: 13',
    '', '',
]));

usleep(500_000);
$handshake = (string) fread($socket, 8192);

check('handshake returns 101', str_contains($handshake, '101'), substr(strtok($handshake, "\r\n") ?: '', 0, 60));

$split = strpos($handshake, "\r\n\r\n");
$buffer = $split === false ? '' : substr($handshake, $split + 4);

$read = static function (float $seconds = 0.6) use ($socket, &$buffer): array {
    $deadline = microtime(true) + $seconds;
    $messages = [];

    while (microtime(true) < $deadline) {
        $chunk = @fread($socket, 65535);

        if ($chunk !== false && $chunk !== '') {
            $buffer .= $chunk;
        }

        foreach (readFrames($buffer) as $raw) {
            $messages[] = json_decode($raw, true);
        }

        usleep(50_000);
    }

    return $messages;
};

$socketId = null;

foreach ($buffer !== '' ? array_map(static fn ($r) => json_decode($r, true), readFrames($buffer)) : $read() as $message) {
    if (($message['event'] ?? '') === 'pusher:connection_established') {
        $socketId = json_decode($message['data'], true)['socket_id'] ?? null;
    }
}

check('connection_established carries a socket id', is_string($socketId) && preg_match('/^\d+\.\d+$/', $socketId) === 1);

// ── an unsigned private subscribe is refused ─────────────────────────────

fwrite($socket, frame((string) json_encode([
    'event' => 'pusher:subscribe',
    'data' => ['channel' => 'private-orders'],
])));

$events = array_column($read(), 'event');

check('an unsigned private subscribe is refused', in_array('pusher:error', $events, true));

// ── a signed one succeeds ────────────────────────────────────────────────

$auth = (new ChannelAuthenticator())->authFor($app, (string) $socketId, 'private-orders');

fwrite($socket, frame((string) json_encode([
    'event' => 'pusher:subscribe',
    'data' => ['channel' => 'private-orders', 'auth' => $auth],
])));

$events = array_column($read(), 'event');

check('a signed private subscribe succeeds', in_array('pusher_internal:subscription_succeeded', $events, true));

// ── publish through the HTTP API ─────────────────────────────────────────

$path = '/apps/demo/events';
$body = (string) json_encode([
    'name' => 'OrderShipped',
    'channel' => 'private-orders',
    'data' => json_encode(['id' => 42]),
]);

$post = static function (string $url, string $body): array {
    $response = @file_get_contents($url, false, stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $body,
        'timeout' => 5,
        'ignore_errors' => true,
    ]]));

    $status = 0;

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
            $status = (int) $m[1];
        }
    }

    return ['status' => $status, 'body' => (string) $response];
};

$query = http_build_query((new RequestSignature())->queryFor($app, 'POST', $path, $body));
$signed = $post('http://' . HOST . ':' . PORT . $path . '?' . $query, $body);

check('a signed publish is accepted', $signed['status'] === 200, $signed['body']);

$delivered = null;

foreach ($read(1.0) as $message) {
    if (($message['event'] ?? '') === 'OrderShipped') {
        $delivered = json_decode($message['data'], true);
    }
}

check('the event arrives over the socket', $delivered === ['id' => 42], json_encode($delivered));

// ── an unsigned publish is refused ───────────────────────────────────────

$unsigned = $post('http://' . HOST . ':' . PORT . $path, $body);

check('an unsigned publish is refused', $unsigned['status'] === 401, (string) $unsigned['status']);

// ── health ───────────────────────────────────────────────────────────────

$health = @file_get_contents('http://' . HOST . ':' . PORT . '/up');

check('the health endpoint answers unsigned', is_string($health) && str_contains($health, '"status":"ok"'));

// ── done ─────────────────────────────────────────────────────────────────

fclose($socket);
$stop();

echo $failures === 0 ? "\nall checks passed\n" : "\n{$failures} check(s) failed\n";

exit($failures === 0 ? 0 : 1);
