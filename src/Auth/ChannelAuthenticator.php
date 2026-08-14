<?php

declare(strict_types=1);

namespace LibxaSocket\Auth;

use LibxaSocket\Application;

/**
 * Signatures for private and presence channel subscriptions.
 *
 * A browser cannot join `private-*` or `presence-*` on its own say-so. It asks
 * your application over HTTP, your application decides whether that user may
 * listen, and hands back a signature. This is both ends of that: the signing
 * your endpoint does, and the verification the socket server does.
 *
 * The signed string is `{socketId}:{channel}`, or for presence channels
 * `{socketId}:{channel}:{channelData}`. Including the socket id is what stops
 * a signature leaked from one browser being replayed from another.
 */
class ChannelAuthenticator
{
    /**
     * The full `auth` value to hand a client: `{key}:{signature}`.
     *
     * @param string|null $channelData for presence channels, the exact JSON
     *        string that will be sent as `channel_data`
     */
    public function authFor(Application $app, string $socketId, string $channel, ?string $channelData = null): string
    {
        return $app->key . ':' . $this->signature($app, $socketId, $channel, $channelData);
    }

    /**
     * The HMAC alone.
     *
     * @param string|null $channelData the exact JSON string, already encoded
     */
    public function signature(Application $app, string $socketId, string $channel, ?string $channelData = null): string
    {
        $payload = $channelData !== null
            ? "{$socketId}:{$channel}:{$channelData}"
            : "{$socketId}:{$channel}";

        return hash_hmac('sha256', $payload, $app->secret);
    }

    /**
     * Verify a signature against the channel data exactly as the client sent it.
     *
     * The string matters, not the value it decodes to. Re-encoding the decoded
     * array here would produce different JSON from what the application signed
     * — different key order, different unicode and slash escaping — and every
     * presence subscription would fail a signature that was in fact correct.
     */
    public function verifySigned(
        Application $app,
        string $socketId,
        string $channel,
        string $signature,
        ?string $channelData = null,
    ): bool {
        return hash_equals(
            $this->signature($app, $socketId, $channel, $channelData),
            $signature,
        );
    }

    /**
     * Sign from an array, encoding it here.
     *
     * For an authorization endpoint that has the user's details as an array:
     * this returns both the `auth` value and the `channel_data` string, and
     * the caller must send back *that* string rather than re-encoding, or the
     * two will not agree.
     *
     * @param array{user_id: string|int, user_info?: array} $channelData
     * @return array{auth: string, channel_data: string}
     */
    public function presenceAuth(Application $app, string $socketId, string $channel, array $channelData): array
    {
        $encoded = json_encode($channelData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'auth' => $this->authFor($app, $socketId, $channel, $encoded),
            'channel_data' => $encoded,
        ];
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
