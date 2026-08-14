<?php

declare(strict_types=1);

namespace LibxaSocket\Tests;

use LibxaSocket\Application;
use LibxaSocket\Auth\ChannelAuthenticator;
use LibxaSocket\Protocol\ChannelManager;
use LibxaSocket\Protocol\Connection;
use LibxaSocket\Protocol\MessageHandler;
use PHPUnit\Framework\TestCase;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;

/**
 * Drives the protocol without a socket, a loop or a framework.
 *
 * Every message a connection is sent is captured, so a test asserts on what
 * actually went over the wire rather than on internal state — which is the
 * only thing a browser can see, and therefore the only thing that has to be
 * right.
 */
abstract class ProtocolTestCase extends TestCase
{
    protected Application $app;

    protected ChannelManager $channels;

    protected ChannelAuthenticator $auth;

    protected MessageHandler $handler;

    /** @var array<string, list<array>> socket id => messages sent */
    protected array $wire = [];

    protected function setUp(): void
    {
        $this->app = new Application('demo', 'demo-key', 'demo-secret');
        $this->channels = new ChannelManager();
        $this->auth = new ChannelAuthenticator();
        $this->handler = new MessageHandler($this->channels, $this->auth);
        $this->wire = [];
    }

    protected function connect(bool $greet = true): Connection
    {
        // Generated first, so the writer closure can capture it: the closure
        // has to know which connection it is recording for, and the connection
        // takes the closure.
        $id = Connection::generateId();

        $this->wire[$id] = [];

        $connection = new Connection(
            id: $id,
            application: $this->app,
            socket: $this->socket(),
            writer: function ($socket, string $payload) use ($id): void {
                $this->wire[$id][] = json_decode($payload, true);
            },
        );

        $this->channels->add($connection);

        if ($greet) {
            $this->handler->established($connection);
            $this->drain($connection);
        }

        return $connection;
    }

    /** Everything sent to a connection since the last drain. */
    protected function sent(Connection $connection): array
    {
        return $this->wire[$connection->id] ?? [];
    }

    protected function drain(Connection $connection): array
    {
        $messages = $this->wire[$connection->id] ?? [];
        $this->wire[$connection->id] = [];

        return $messages;
    }

    /** The last message sent to a connection, with `data` decoded. */
    protected function lastMessage(Connection $connection): ?array
    {
        $messages = $this->sent($connection);
        $message = end($messages);

        if ($message === false) {
            return null;
        }

        if (isset($message['data']) && is_string($message['data'])) {
            $message['data'] = json_decode($message['data'], true);
        }

        return $message;
    }

    protected function lastEvent(Connection $connection): ?string
    {
        return $this->lastMessage($connection)['event'] ?? null;
    }

    /** @param array<string, mixed> $data */
    protected function send(Connection $connection, string $event, array $data = [], ?string $channel = null): void
    {
        $message = ['event' => $event, 'data' => $data];

        if ($channel !== null) {
            $message['channel'] = $channel;
        }

        $this->handler->handle($connection, (string) json_encode($message));
    }

    protected function subscribe(Connection $connection, string $channel): void
    {
        $data = ['channel' => $channel];

        if (str_starts_with($channel, 'private-')) {
            $data['auth'] = $this->auth->authFor($this->app, $connection->id, $channel);
        }

        $this->send($connection, 'pusher:subscribe', $data);
    }

    /** @param array<string, mixed> $userInfo */
    protected function joinPresence(Connection $connection, string $channel, string $userId, array $userInfo = []): void
    {
        $signed = $this->auth->presenceAuth($this->app, $connection->id, $channel, [
            'user_id' => $userId,
            'user_info' => $userInfo,
        ]);

        $this->send($connection, 'pusher:subscribe', [
            'channel' => $channel,
            'auth' => $signed['auth'],
            'channel_data' => $signed['channel_data'],
        ]);
    }

    private function socket(): ConnectionInterface
    {
        return new class implements ConnectionInterface {
            public function getRemoteAddress(): ?string { return '127.0.0.1:1'; }
            public function getLocalAddress(): ?string { return '127.0.0.1:8080'; }
            public function isReadable(): bool { return true; }
            public function isWritable(): bool { return true; }
            public function pause(): void {}
            public function resume(): void {}
            public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface { return $dest; }
            public function write($data): bool { return true; }
            public function end($data = null): void {}
            public function close(): void {}
            public function on($event, callable $listener): void {}
            public function once($event, callable $listener): void {}
            public function removeListener($event, callable $listener): void {}
            public function removeAllListeners($event = null): void {}
            public function listeners($event = null): array { return []; }
            public function emit($event, array $arguments = []): void {}
        };
    }
}
