<?php

declare(strict_types=1);

namespace LibxaSocket\Auth;

use LibxaSocket\Application;

/**
 * Verifies signed channel-authorization requests for private/presence
 * channels (mirrors Pusher/Reverb's auth scheme: the browser can't join
 * `private-*` or `presence-*` channels without a signature the server-side
 * app vouches for).
 *
 * The signed string is `{socketId}:{channelName}` — or, for presence
 * channels, `{socketId}:{channelName}:{channelData}` where channelData is
 * the JSON-encoded user info the channel will expose to other members.
 */
class ChannelAuthenticator
{
    /**
     * Compute the auth signature an HTTP endpoint should hand back to the
     * client for it to present when subscribing over the socket.
     */
    public function sign(Application $app, string $socketId, string $channel, ?array $channelData = null): string
    {
        $string = $channelData !== null
            ? "{$socketId}:{$channel}:" . json_encode($channelData, JSON_UNESCAPED_UNICODE)
            : "{$socketId}:{$channel}";

        return hash_hmac('sha256', $string, $app->secret);
    }

    /**
     * Verify a signature presented by a client at subscribe time.
     */
    public function verify(Application $app, string $socketId, string $channel, string $signature, ?array $channelData = null): bool
    {
        $expected = $this->sign($app, $socketId, $channel, $channelData);

        return hash_equals($expected, $signature);
    }

    public function isProtectedChannel(string $channel): bool
    {
        return str_starts_with($channel, 'private-') || str_starts_with($channel, 'presence-');
    }

    public function isPresenceChannel(string $channel): bool
    {
        return str_starts_with($channel, 'presence-');
    }
}
