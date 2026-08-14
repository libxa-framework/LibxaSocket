<?php

declare(strict_types=1);

namespace LibxaSocket\Protocol;

use LibxaSocket\Auth\ChannelAuthenticator;

/**
 * The Pusher protocol, client side.
 *
 * Everything a browser can send arrives here. There are only four things it
 * is allowed to say — subscribe, unsubscribe, ping, and a client event — and
 * anything else is answered with an error rather than ignored, because a
 * client library that gets silence cannot tell a rejected message from a lost
 * one.
 *
 * Implementing the wire protocol rather than inventing one is the entire
 * reason Laravel Echo, pusher-js and every other Pusher client work against
 * this server without a shim.
 */
class MessageHandler
{
    /** Pusher close/error codes. 4000-4099 mean "do not retry". */
    public const ERROR_INVALID_MESSAGE = 4009;

    public const ERROR_UNAUTHORIZED = 4009;

    public const ERROR_UNSUPPORTED = 4301;

    /** How long a client may be silent before we ping it, in seconds. */
    public const ACTIVITY_TIMEOUT = 30;

    public function __construct(
        private readonly ChannelManager $channels,
        private readonly ChannelAuthenticator $authenticator,
        private readonly bool $allowClientEvents = true,
    ) {
    }

    /** Greet a new connection. */
    public function established(Connection $connection): void
    {
        $connection->sendEvent('pusher:connection_established', [
            'socket_id' => $connection->id,
            'activity_timeout' => self::ACTIVITY_TIMEOUT,
        ]);
    }

    /** Handle one inbound frame. */
    public function handle(Connection $connection, string $payload): void
    {
        $connection->touch();

        $message = json_decode($payload, true);

        if (! is_array($message) || ! isset($message['event']) || ! is_string($message['event'])) {
            $connection->sendError(self::ERROR_INVALID_MESSAGE, 'Invalid message.');

            return;
        }

        $event = $message['event'];
        $data = $this->dataOf($message);

        match (true) {
            $event === 'pusher:subscribe' => $this->subscribe($connection, $data),
            $event === 'pusher:unsubscribe' => $this->unsubscribe($connection, $data),
            $event === 'pusher:ping' => $connection->sendEvent('pusher:pong', []),
            $event === 'pusher:pong' => null, // touch() above is the whole handling
            str_starts_with($event, 'client-') => $this->clientEvent($connection, $event, $message),
            default => $connection->sendError(self::ERROR_UNSUPPORTED, "Unknown event [{$event}]."),
        };
    }

    // ── subscribe ────────────────────────────────────────────────────────

    private function subscribe(Connection $connection, array $data): void
    {
        $name = $data['channel'] ?? null;

        if (! is_string($name) || $name === '') {
            $connection->sendError(self::ERROR_INVALID_MESSAGE, 'No channel given.');

            return;
        }

        // Re-subscribing to a channel you are already in is a no-op rather
        // than an error: clients resubscribe on reconnect, and a reconnect
        // that races the old socket's cleanup is normal, not a fault.
        if ($connection->hasJoined($name)) {
            return;
        }

        $member = null;

        if (Channel::isPrivateName($name)) {
            $auth = is_string($data['auth'] ?? null) ? $data['auth'] : '';
            $channelData = is_string($data['channel_data'] ?? null) ? $data['channel_data'] : null;

            if (! $this->authorize($connection, $name, $auth, $channelData)) {
                $connection->sendError(
                    self::ERROR_UNAUTHORIZED,
                    "Not authorized to join [{$name}].",
                );

                return;
            }

            if (Channel::isPresenceName($name)) {
                $member = $this->memberFrom($channelData);

                if ($member === null) {
                    $connection->sendError(
                        self::ERROR_INVALID_MESSAGE,
                        'A presence channel needs channel_data with a user_id.',
                    );

                    return;
                }
            }
        }

        $channel = $this->channels->channel($connection->application->id, $name);

        // Read the roster before joining, so the new member does not appear in
        // the list of people who were already here.
        $alreadyPresent = $channel->isPresence()
            ? $channel->hasOtherConnectionFor((string) $member['user_id'], $connection->id)
            : false;

        $channel->subscribe($connection, $member);

        $connection->sendEvent(
            'pusher_internal:subscription_succeeded',
            $channel->isPresence() ? $channel->presencePayload() : [],
            $name,
        );

        // Announced only when the user was not already here on another
        // connection. Two tabs is one member.
        if ($channel->isPresence() && ! $alreadyPresent) {
            $channel->broadcast(
                'pusher_internal:member_added',
                ['user_id' => $member['user_id'], 'user_info' => $member['user_info']],
                $connection->id,
            );
        }
    }

    private function unsubscribe(Connection $connection, array $data): void
    {
        $name = $data['channel'] ?? null;

        if (! is_string($name) || ! $connection->hasJoined($name)) {
            return;
        }

        $channel = $this->channels->existing($connection->application->id, $name);
        $member = $channel?->member($connection->id);

        $this->channels->unsubscribe($connection->application->id, $name, $connection);

        if ($channel !== null && $channel->isPresence() && $member !== null) {
            $this->announceDeparture($channel, $connection->id, $member);
        }
    }

    /**
     * Tell a presence channel somebody left, if they really have.
     *
     * @param array{user_id: string, user_info: array} $member
     */
    public function announceDeparture(Channel $channel, string $socketId, array $member): void
    {
        if ($channel->hasOtherConnectionFor((string) $member['user_id'], $socketId)) {
            return;
        }

        $channel->broadcast(
            'pusher_internal:member_removed',
            ['user_id' => $member['user_id']],
            $socketId,
        );
    }

    // ── client events ────────────────────────────────────────────────────

    /**
     * Relay a `client-*` event to the rest of a channel.
     *
     * Three rules, all of them load-bearing:
     *
     *   - Only on private and presence channels. A public channel anybody can
     *     join is one anybody can publish to, which is a spam relay.
     *   - Only from a connection that has actually joined the channel.
     *   - Never back to the sender.
     */
    private function clientEvent(Connection $connection, string $event, array $message): void
    {
        if (! $this->allowClientEvents) {
            $connection->sendError(self::ERROR_UNSUPPORTED, 'Client events are disabled.');

            return;
        }

        $name = $message['channel'] ?? null;

        if (! is_string($name) || ! Channel::isPrivateName($name)) {
            $connection->sendError(
                self::ERROR_UNAUTHORIZED,
                'Client events are only allowed on private and presence channels.',
            );

            return;
        }

        if (! $connection->hasJoined($name)) {
            $connection->sendError(
                self::ERROR_UNAUTHORIZED,
                "You are not subscribed to [{$name}].",
            );

            return;
        }

        $channel = $this->channels->existing($connection->application->id, $name);

        $channel?->broadcast($event, $message['data'] ?? [], $connection->id);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * Check the signature a client presents for a private or presence channel.
     *
     * The signature covers the socket id, so one minted for a different
     * connection cannot be replayed here — which is what stops a token leaked
     * from one browser being used from another.
     */
    private function authorize(Connection $connection, string $channel, string $auth, ?string $channelData): bool
    {
        // Pusher's auth string is "{appKey}:{signature}".
        $parts = explode(':', $auth, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$key, $signature] = $parts;

        if (! hash_equals($connection->application->key, $key)) {
            return false;
        }

        return $this->authenticator->verifySigned(
            $connection->application,
            $connection->id,
            $channel,
            $signature,
            $channelData,
        );
    }

    /**
     * The member a presence subscription describes.
     *
     * @return array{user_id: string, user_info: array}|null
     */
    private function memberFrom(?string $channelData): ?array
    {
        if ($channelData === null) {
            return null;
        }

        $decoded = json_decode($channelData, true);

        if (! is_array($decoded) || ! isset($decoded['user_id'])) {
            return null;
        }

        return [
            // Cast, because pusher-js sends whatever the application returned
            // and an integer id there would make the roster keys inconsistent
            // with the string ids everything else uses.
            'user_id' => (string) $decoded['user_id'],
            'user_info' => is_array($decoded['user_info'] ?? null) ? $decoded['user_info'] : [],
        ];
    }

    /**
     * A message's data, whether it arrived as an object or a JSON string.
     *
     * Client libraries differ, and the specification allows both.
     */
    private function dataOf(array $message): array
    {
        $data = $message['data'] ?? [];

        if (is_string($data)) {
            $decoded = json_decode($data, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($data) ? $data : [];
    }
}
