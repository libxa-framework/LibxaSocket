<?php

declare(strict_types=1);

namespace LibxaSocket\Protocol;

/**
 * A channel, and the connections subscribed to it.
 *
 * Three kinds, distinguished only by the name prefix, exactly as Pusher
 * defines them:
 *
 *   public          anyone may subscribe
 *   private-*       requires a signature from your application
 *   presence-*      requires a signature, and carries a roster of members
 *
 * The prefix is the whole rule. There is no separate registry saying which
 * channels are private, which means an application cannot accidentally leave
 * a channel unprotected by forgetting to declare it — naming it `private-`
 * is the declaration.
 */
class Channel
{
    /** @var array<string, Connection> socket id => connection */
    protected array $connections = [];

    /**
     * Presence members, by socket id.
     *
     * Kept per connection rather than per user, because one user can be
     * connected twice and the roster must not lose them when one tab closes.
     *
     * @var array<string, array{user_id: string, user_info: array}>
     */
    protected array $members = [];

    public function __construct(public readonly string $name)
    {
    }

    public function isPrivate(): bool
    {
        return str_starts_with($this->name, 'private-') || $this->isPresence();
    }

    public function isPresence(): bool
    {
        return str_starts_with($this->name, 'presence-');
    }

    public static function isPrivateName(string $name): bool
    {
        return str_starts_with($name, 'private-')
            || str_starts_with($name, 'presence-')
            || str_starts_with($name, 'private-encrypted-');
    }

    public static function isPresenceName(string $name): bool
    {
        return str_starts_with($name, 'presence-');
    }

    // ── membership ───────────────────────────────────────────────────────

    /** @param array{user_id: string, user_info: array}|null $member */
    public function subscribe(Connection $connection, ?array $member = null): void
    {
        $this->connections[$connection->id] = $connection;

        if ($member !== null) {
            $this->members[$connection->id] = $member;
        }

        $connection->join($this->name);
    }

    public function unsubscribe(Connection $connection): void
    {
        unset($this->connections[$connection->id], $this->members[$connection->id]);

        $connection->leave($this->name);
    }

    public function has(Connection $connection): bool
    {
        return isset($this->connections[$connection->id]);
    }

    public function isEmpty(): bool
    {
        return $this->connections === [];
    }

    public function count(): int
    {
        return count($this->connections);
    }

    /** @return array<string, Connection> */
    public function connections(): array
    {
        return $this->connections;
    }

    // ── presence roster ──────────────────────────────────────────────────

    /**
     * The member behind a socket, if any.
     *
     * @return array{user_id: string, user_info: array}|null
     */
    public function member(string $socketId): ?array
    {
        return $this->members[$socketId] ?? null;
    }

    /**
     * Whether a user id is still present on some other connection.
     *
     * The reason member_removed is not sent the moment a socket closes: a user
     * with two tabs open who closes one has not left, and telling everybody
     * they did produces a roster that disagrees with itself.
     */
    public function hasOtherConnectionFor(string $userId, string $exceptSocketId): bool
    {
        foreach ($this->members as $socketId => $member) {
            if ($socketId !== $exceptSocketId && $member['user_id'] === $userId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The roster, in the shape pusher_internal:subscription_succeeded wants.
     *
     * Deduplicated by user id, because the same person on two devices is one
     * member of the channel and counting them twice makes "3 people online"
     * wrong in the only way users notice.
     *
     * @return array{presence: array{ids: list<string>, hash: array<string, array>, count: int}}
     */
    public function presencePayload(): array
    {
        $hash = [];

        foreach ($this->members as $member) {
            $hash[$member['user_id']] = $member['user_info'];
        }

        $ids = array_map('strval', array_keys($hash));

        return [
            'presence' => [
                'ids' => array_values($ids),
                'hash' => $hash,
                'count' => count($hash),
            ],
        ];
    }

    // ── sending ──────────────────────────────────────────────────────────

    /**
     * Send an event to everyone here.
     *
     * `$exceptSocketId` is how `socket_id` on a published event works: the
     * client that caused the change already rendered it optimistically, and
     * echoing it back makes the change appear to happen twice.
     */
    public function broadcast(string $event, array|string|null $data, ?string $exceptSocketId = null): int
    {
        $sent = 0;

        foreach ($this->connections as $socketId => $connection) {
            if ($socketId === $exceptSocketId) {
                continue;
            }

            $connection->sendEvent($event, $data, $this->name);
            $sent++;
        }

        return $sent;
    }
}
