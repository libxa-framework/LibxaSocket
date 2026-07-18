<?php

declare(strict_types=1);

namespace LibxaSocket;

use Workerman\Connection\TcpConnection;
use Libxa\Auth\Guard;
use LibxaSocket\Server;

/**
 * Libxa WebSocket Connection Wrapper
 */
class WsConnection
{
    /** @var array Data stored on the connection (session-like) */
    protected array $storage = [];

    /** @var array Registered rooms for this connection */
    protected array $rooms = [];

    public string $id;

    public function __construct(
        protected TcpConnection $connection,
        protected Server        $server
    ) {
        $this->id = (string) $connection->id;
    }

    public function id(): string
    {
        return (string) $this->connection->id;
    }

    /**
     * Send an event to the client.
     */
    public function send(string|array $event, mixed $data = []): void
    {
        if (is_array($event)) {
            $payload = $event;
        } else {
            $payload = [
                'event' => $event,
                'data'  => $data,
            ];
        }

        $this->connection->send(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Send an error to the client.
     */
    public function error(string $message, string $code = 'error'): void
    {
        $this->send('error', [
            'code'    => $code,
            'message' => $message
        ]);
    }

    /**
     * Join a room.
     */
    public function join(string $room): void
    {
        $this->rooms[$room] = true;
        $this->server->joinRoom($room, $this->id(), $this->connection);
    }

    /**
     * Leave a room.
     */
    public function leave(string $room): void
    {
        unset($this->rooms[$room]);
        $this->server->leaveRoom($room, $this->id());
    }

    /**
     * Broadcast to everyone in a room.
     */
    public function broadcastToRoom(string $room, string $event, mixed $data = []): void
    {
        $this->server->broadcastToRoom($room, $event, $data);
    }

    /**
     * Broadcast to everyone in a room EXCEPT the sender.
     */
    public function broadcastToRoomExcept(string $room, self $except, string $event, mixed $data = []): void
    {
        $payload = json_encode(['event' => $event, 'data' => $data], JSON_UNESCAPED_UNICODE);
        
        $conns = $this->connection->worker->rooms[$room] ?? [];
        foreach ($conns as $id => $conn) {
            if ($id === $except->id()) continue;
            $conn->send($payload);
        }
    }

    /**
     * Get URL parameter by name.
     */
    public function param(string $name, mixed $default = null): mixed
    {
        return $this->storage['params'][$name] ?? $default;
    }

    public function setParams(array $params): void
    {
        $this->storage['params'] = $params;
    }

    /**
     * Get the authenticated user.
     */
    public function user(): ?object
    {
        return $this->storage['user'] ?? null;
    }

    /**
     * Check if the user has a capability.
     */
    public function can(string $ability, ...$arguments): bool
    {
        $user = $this->user();
        if (!$user) return false;
        
        // This would integrate with Libxa Auth/Gate system
        return true; 
    }

    /**
     * Close the connection.
     */
    public function close(int $code = 1000, string $reason = ''): void
    {
        // Workerman's close method doesn't naturally support WS codes easily in plain TCP mode
        // but it works for WS connections.
        $this->connection->close();
    }

    public function setUser($user): void
    {
        $this->storage['user'] = $user;
    }
}
