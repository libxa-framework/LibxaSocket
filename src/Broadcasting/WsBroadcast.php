<?php

declare(strict_types=1);

namespace LibxaSocket\Broadcasting;

use Libxa\Foundation\Application;

/**
 * Facade-like class for broadcasting events to WebSocket channels from anywhere.
 */
class WsBroadcast
{
    protected string $channel;

    public function __construct(protected Application $app)
    {
    }

    /**
     * Target a specific channel or user room.
     */
    public static function to(string $channel): self
    {
        $instance = app(self::class);
        $instance->channel = $channel;
        return $instance;
    }

    /**
     * Target a room (alias for to).
     */
    public static function toRoom(string $room): self
    {
        return self::to($room);
    }

    /**
     * Emit an event to the targeted channel/room.
     */
    public function emit(string $event, mixed $data = []): void
    {
        // Internal implementation:
        // In a multi-process environment (Workerman/PHP-FPM), we would normally
        // use a Redis pub/sub or a local socket to the WsServer.
        
        // For now, we will log it or use a simple file-based bridge if needed,
        // but ideally we integration with the existing Broadcaster.
        
        /** @var \Libxa\Broadcasting\BroadcastManager $manager */
        $manager = $this->app->make('broadcast');
        
        // We create a "virtual" ShouldBroadcast event for the manager
        $manager->connection('Libxa')->broadcast([$this->channel], $event, (array) $data);
    }
}
