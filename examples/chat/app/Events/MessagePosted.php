<?php

declare(strict_types=1);

namespace App\Events;

use Libxa\Broadcasting\ShouldBroadcast;

/**
 * Somebody said something in a room.
 *
 * Implementing ShouldBroadcast is the whole contract: `broadcast(new
 * MessagePosted(...))` hands it to the configured broadcaster, which signs it
 * and posts it to the socket server, which fans it out to everyone subscribed
 * to the channel this names.
 */
final class MessagePosted implements ShouldBroadcast
{
    public function __construct(
        public readonly string $room,
        public readonly string $author,
        public readonly string $body,
        public readonly string $postedAt,
    ) {
    }

    /**
     * A presence channel, so the page can show who is in the room as well as
     * what was said. `presence-` is not decoration: it is what makes the
     * server demand a signature before letting anyone listen.
     */
    public function broadcastOn(): array
    {
        return ['presence-room.' . $this->room];
    }

    public function broadcastWith(): array
    {
        return [
            'author' => $this->author,
            'body' => $this->body,
            'posted_at' => $this->postedAt,
        ];
    }

    public function broadcastAs(): string
    {
        return 'MessagePosted';
    }
}
