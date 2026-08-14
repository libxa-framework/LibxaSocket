<?php

declare(strict_types=1);

namespace LibxaSocket\Protocol;

/**
 * Every channel, for every application, and who is in them.
 *
 * Scoped by application id throughout. Two apps running on one server must
 * never see each other's channels — the whole point of registering more than
 * one is that they are separate — and the way that goes wrong is a lookup
 * that takes a channel name without an app.
 */
class ChannelManager
{
    /** @var array<string, array<string, Channel>> app id => name => channel */
    private array $channels = [];

    /** @var array<string, Connection> socket id => connection */
    private array $connections = [];

    public function add(Connection $connection): void
    {
        $this->connections[$connection->id] = $connection;
    }

    public function find(string $socketId): ?Connection
    {
        return $this->connections[$socketId] ?? null;
    }

    public function connectionCount(): int
    {
        return count($this->connections);
    }

    /** @return array<string, Connection> */
    public function allConnections(): array
    {
        return $this->connections;
    }

    /**
     * Forget a connection, leaving every channel it had joined.
     *
     * @return list<array{channel: Channel, member: array|null}> what it left, so
     *         presence departures can be announced by the caller
     */
    public function remove(Connection $connection): array
    {
        $left = [];

        foreach ($connection->channels() as $name) {
            $channel = $this->channels[$connection->application->id][$name] ?? null;

            if ($channel === null) {
                continue;
            }

            $member = $channel->member($connection->id);

            $channel->unsubscribe($connection);

            $left[] = ['channel' => $channel, 'member' => $member];

            $this->forgetIfEmpty($connection->application->id, $channel);
        }

        unset($this->connections[$connection->id]);

        return $left;
    }

    public function channel(string $appId, string $name): Channel
    {
        return $this->channels[$appId][$name] ??= new Channel($name);
    }

    public function existing(string $appId, string $name): ?Channel
    {
        return $this->channels[$appId][$name] ?? null;
    }

    /** @return array<string, Channel> */
    public function all(string $appId): array
    {
        return $this->channels[$appId] ?? [];
    }

    public function unsubscribe(string $appId, string $name, Connection $connection): ?Channel
    {
        $channel = $this->existing($appId, $name);

        if ($channel === null) {
            return null;
        }

        $channel->unsubscribe($connection);

        $this->forgetIfEmpty($appId, $channel);

        return $channel;
    }

    /**
     * Drop a channel once the last subscriber leaves.
     *
     * Without this the map grows for the lifetime of the process: a chat
     * application that names a channel per conversation would hold every
     * conversation ever opened, empty, until restart.
     */
    private function forgetIfEmpty(string $appId, Channel $channel): void
    {
        if ($channel->isEmpty()) {
            unset($this->channels[$appId][$channel->name]);
        }
    }
}
