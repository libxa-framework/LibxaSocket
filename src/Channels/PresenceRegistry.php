<?php

declare(strict_types=1);

namespace LibxaSocket\Channels;

/**
 * Tracks who's currently subscribed to each presence-* channel, so the
 * server can tell newly-joining members who's already there, and tell
 * existing members when someone joins/leaves.
 *
 * In-process only (per Workerman worker). Fine for a single-process
 * deployment; for multi-worker/horizontal scaling this would need to move
 * to a shared store (Redis) — see README for notes on that tradeoff.
 */
class PresenceRegistry
{
    /**
     * @var array<string, array<string, array>> channel => [connectionId => userInfo]
     */
    protected array $members = [];

    public function join(string $channel, string $connectionId, array $userInfo): void
    {
        $this->members[$channel][$connectionId] = $userInfo;
    }

    public function leave(string $channel, string $connectionId): void
    {
        unset($this->members[$channel][$connectionId]);

        if (empty($this->members[$channel])) {
            unset($this->members[$channel]);
        }
    }

    /**
     * Remove a connection from every presence channel it was in (called on
     * disconnect). Returns the list of channels it was removed from, so the
     * caller can announce the departure to each one.
     *
     * @return string[]
     */
    public function leaveAll(string $connectionId): array
    {
        $left = [];

        foreach ($this->members as $channel => $members) {
            if (isset($members[$connectionId])) {
                $this->leave($channel, $connectionId);
                $left[] = $channel;
            }
        }

        return $left;
    }

    public function members(string $channel): array
    {
        return $this->members[$channel] ?? [];
    }

    public function count(string $channel): int
    {
        return count($this->members[$channel] ?? []);
    }
}
