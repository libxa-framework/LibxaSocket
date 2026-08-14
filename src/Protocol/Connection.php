<?php

declare(strict_types=1);

namespace LibxaSocket\Protocol;

use LibxaSocket\Application;
use Ratchet\RFC6455\Messaging\Frame;
use React\Socket\ConnectionInterface;

/**
 * One connected client.
 *
 * Everything the protocol needs to know about a socket: which application it
 * authenticated against, the id it was given, the channels it has joined, and
 * when it was last heard from.
 *
 * The socket id is not decoration. It is signed into every private and
 * presence authorization, so a signature minted for one connection cannot be
 * replayed by another, and it is what `socket_id` on a published event excludes
 * so the client that caused a change does not receive its own echo.
 */
class Connection
{
    /** @var array<string, true> channel name => joined */
    private array $channels = [];

    private float $lastSeenAt;

    /** Set once the client answers a ping; cleared when a new ping goes out. */
    private bool $awaitingPong = false;

    public function __construct(
        public readonly string $id,
        public readonly Application $application,
        private readonly ConnectionInterface $socket,
        private readonly \Closure $writer,
    ) {
        $this->lastSeenAt = microtime(true);
    }

    /**
     * A Pusher socket id.
     *
     * Two random integers joined by a dot, which is the shape every client
     * library expects — pusher-js puts it in the body of authorization
     * requests, and a value that does not look like this is rejected there
     * rather than here.
     */
    public static function generateId(): string
    {
        return random_int(1, 1_000_000_000) . '.' . random_int(1, 1_000_000_000);
    }

    /** Send an already-shaped protocol message. */
    public function send(array $message): void
    {
        ($this->writer)($this->socket, json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Send a protocol event.
     *
     * `data` goes out as a JSON-encoded *string*, not a nested object. That is
     * what the Pusher wire format specifies and what every client library
     * round-trips; sending an object happens to work with pusher-js, which
     * parses either, but not with everything else.
     */
    public function sendEvent(string $event, array|string|null $data = null, ?string $channel = null): void
    {
        $message = ['event' => $event];

        if ($channel !== null) {
            $message['channel'] = $channel;
        }

        if ($data !== null) {
            $message['data'] = is_string($data)
                ? $data
                : json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $this->send($message);
    }

    /**
     * Report a protocol error.
     *
     * Codes in the 4000-4099 range tell the client not to retry: the
     * connection is wrong in a way that reconnecting will not fix. pusher-js
     * acts on that, so getting the range right is the difference between a
     * client that stops and one that reconnects in a loop forever.
     */
    public function sendError(int $code, string $message): void
    {
        $this->send([
            'event' => 'pusher:error',
            'data' => ['code' => $code, 'message' => $message],
        ]);
    }

    public function close(): void
    {
        $this->socket->close();
    }

    public function socket(): ConnectionInterface
    {
        return $this->socket;
    }

    // ── channels ─────────────────────────────────────────────────────────

    public function join(string $channel): void
    {
        $this->channels[$channel] = true;
    }

    public function leave(string $channel): void
    {
        unset($this->channels[$channel]);
    }

    public function hasJoined(string $channel): bool
    {
        return isset($this->channels[$channel]);
    }

    /** @return list<string> */
    public function channels(): array
    {
        return array_keys($this->channels);
    }

    // ── liveness ─────────────────────────────────────────────────────────

    public function touch(): void
    {
        $this->lastSeenAt = microtime(true);
        $this->awaitingPong = false;
    }

    public function idleFor(): float
    {
        return microtime(true) - $this->lastSeenAt;
    }

    public function ping(): void
    {
        $this->awaitingPong = true;

        // A protocol-level pusher:ping rather than a WebSocket control frame:
        // browsers answer control pings in the network stack without the page
        // being alive, so a control-frame pong proves the machine is up, not
        // that the client still is.
        $this->sendEvent('pusher:ping', []);
    }

    public function isAwaitingPong(): bool
    {
        return $this->awaitingPong;
    }

    /** The raw frame opcode used for outbound text. */
    public static function textFrame(string $payload): Frame
    {
        return new Frame($payload, true, Frame::OP_TEXT);
    }
}
