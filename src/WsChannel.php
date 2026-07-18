<?php

declare(strict_types=1);

namespace LibxaSocket;

use Throwable;

/**
 * Base WebSocket Channel class.
 * All custom channels must extend this class.
 */
abstract class WsChannel
{
    /**
     * Called when a client completes the handshake.
     */
    public function onOpen(WsConnection $connection): void
    {
        //
    }

    /**
     * Called when a client sends a message.
     */
    public function onMessage(WsConnection $connection, WsMessage $message): void
    {
        //
    }

    /**
     * Called when a client disconnects.
     */
    public function onClose(WsConnection $connection): void
    {
        //
    }

    /**
     * Called when an exception occurs in the channel.
     */
    public function onError(WsConnection $connection, Throwable $e): void
    {
        //
    }
}
