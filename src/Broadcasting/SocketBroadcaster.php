<?php

declare(strict_types=1);

namespace LibxaSocket\Broadcasting;

use LibxaSocket\Application;
use LibxaSocket\Server\RequestSignature;
use Libxa\Broadcasting\Broadcaster;

/**
 * Publishes events from your application to the socket server.
 *
 * The other half of the loop: the server fans out to browsers, this is how a
 * request handler or a queued job gets an event to the server in the first
 * place. It signs and POSTs to `/apps/{id}/events`, which is Pusher's REST
 * API, so pointing this at Pusher itself instead would also work.
 *
 * Delivery is best-effort by design. A socket server that is down must not
 * take an HTTP request down with it: the user's order was placed, and the
 * live update not arriving is worth logging, not worth a 500. Anything that
 * genuinely cannot tolerate a lost event needs a queue, not a tighter timeout
 * here.
 */
class SocketBroadcaster implements Broadcaster
{
    private RequestSignature $signature;

    public function __construct(
        private readonly Application $app,
        private readonly string $host,
        private readonly int $port,
        private readonly bool $useTls = false,
        private readonly float $timeout = 2.0,
        private readonly ?\Closure $reporter = null,
    ) {
        $this->signature = new RequestSignature();
    }

    /**
     * @param list<string> $channels
     */
    public function broadcast(array $channels, string $event, array $payload): void
    {
        $channels = array_values(array_filter($channels, static fn ($c): bool => is_string($c) && $c !== ''));

        if ($channels === []) {
            return;
        }

        $body = json_encode([
            'name' => $event,
            'channels' => $channels,
            // Encoded here, once. The server passes `data` through untouched,
            // so encoding it there as well would deliver double-encoded JSON.
            'data' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ], JSON_UNESCAPED_SLASHES);

        if ($body === false) {
            $this->report("Could not encode the payload for [{$event}].");

            return;
        }

        $this->post('/apps/' . rawurlencode($this->app->id) . '/events', $body, $event);
    }

    private function post(string $path, string $body, string $event): void
    {
        $query = http_build_query(
            $this->signature->queryFor($this->app, 'POST', $path, $body),
        );

        $scheme = $this->useTls ? 'https' : 'http';
        $url = "{$scheme}://{$this->host}:{$this->port}{$path}?{$query}";

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n",
                'content' => $body,
                'timeout' => $this->timeout,
                // So a 401 comes back as a response to report rather than as a
                // warning with no detail.
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => $this->useTls,
                'verify_peer_name' => $this->useTls,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $this->report("Could not reach the socket server at {$this->host}:{$this->port} to publish [{$event}].");

            return;
        }

        $status = $this->statusFrom($http_response_header ?? []);

        if ($status !== null && $status >= 400) {
            $this->report("The socket server refused [{$event}] with {$status}: {$response}");
        }
    }

    /** @param list<string> $headers */
    private function statusFrom(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }

    private function report(string $message): void
    {
        if ($this->reporter !== null) {
            ($this->reporter)($message);

            return;
        }

        if (! function_exists('logger')) {
            return;
        }

        $logger = logger();

        if (is_object($logger) && method_exists($logger, 'warning')) {
            $logger->warning('LibxaSocket: ' . $message);
        }
    }
}
