<?php

declare(strict_types=1);

namespace LibxaSocket\Tests;

use LibxaSocket\Protocol\MessageHandler;

/**
 * Joining channels.
 *
 * The prefix is the whole access-control rule — `private-` and `presence-`
 * require a signature, everything else does not — so these tests are the
 * boundary between a private channel and a public one.
 */
final class SubscriptionTest extends ProtocolTestCase
{
    public function test_a_new_connection_is_told_its_socket_id(): void
    {
        $connection = $this->connect(greet: false);

        $this->handler->established($connection);

        $message = $this->lastMessage($connection);

        self::assertSame('pusher:connection_established', $message['event']);
        self::assertSame($connection->id, $message['data']['socket_id']);
        self::assertSame(MessageHandler::ACTIVITY_TIMEOUT, $message['data']['activity_timeout']);
    }

    public function test_a_socket_id_looks_like_a_socket_id(): void
    {
        // pusher-js puts this in the body of every authorization request and
        // rejects a value that does not match, far from here.
        self::assertMatchesRegularExpression('/^\d+\.\d+$/', $this->connect()->id);
    }

    public function test_anybody_may_join_a_public_channel(): void
    {
        $connection = $this->connect();

        $this->subscribe($connection, 'orders');

        self::assertSame('pusher_internal:subscription_succeeded', $this->lastEvent($connection));
        self::assertTrue($connection->hasJoined('orders'));
    }

    // ── private channels ─────────────────────────────────────────────────

    public function test_a_private_channel_needs_a_signature(): void
    {
        $connection = $this->connect();

        $this->send($connection, 'pusher:subscribe', ['channel' => 'private-orders']);

        self::assertSame('pusher:error', $this->lastEvent($connection));
        self::assertFalse($connection->hasJoined('private-orders'));
    }

    public function test_a_valid_signature_joins_a_private_channel(): void
    {
        $connection = $this->connect();

        $this->subscribe($connection, 'private-orders');

        self::assertSame('pusher_internal:subscription_succeeded', $this->lastEvent($connection));
        self::assertTrue($connection->hasJoined('private-orders'));
    }

    public function test_a_signature_for_another_connection_is_refused(): void
    {
        // The reason the socket id is in the signed string: a token leaked
        // from one browser is useless from another.
        $connection = $this->connect();

        $this->send($connection, 'pusher:subscribe', [
            'channel' => 'private-orders',
            'auth' => $this->auth->authFor($this->app, '999.999', 'private-orders'),
        ]);

        self::assertSame('pusher:error', $this->lastEvent($connection));
        self::assertFalse($connection->hasJoined('private-orders'));
    }

    public function test_a_signature_for_another_channel_is_refused(): void
    {
        $connection = $this->connect();

        $this->send($connection, 'pusher:subscribe', [
            'channel' => 'private-payroll',
            'auth' => $this->auth->authFor($this->app, $connection->id, 'private-orders'),
        ]);

        self::assertSame('pusher:error', $this->lastEvent($connection));
    }

    public function test_a_signature_from_another_application_is_refused(): void
    {
        $connection = $this->connect();

        $other = new \LibxaSocket\Application('other', 'other-key', 'other-secret');

        $this->send($connection, 'pusher:subscribe', [
            'channel' => 'private-orders',
            'auth' => $this->auth->authFor($other, $connection->id, 'private-orders'),
        ]);

        self::assertSame('pusher:error', $this->lastEvent($connection));
    }

    public function test_a_malformed_auth_string_is_refused_rather_than_erroring(): void
    {
        $connection = $this->connect();

        foreach (['', 'nonsense', ':', 'demo-key:'] as $auth) {
            $this->drain($connection);

            $this->send($connection, 'pusher:subscribe', ['channel' => 'private-orders', 'auth' => $auth]);

            self::assertSame('pusher:error', $this->lastEvent($connection), "auth [{$auth}]");
        }
    }

    // ── unsubscribing ────────────────────────────────────────────────────

    public function test_unsubscribing_leaves_the_channel(): void
    {
        $connection = $this->connect();

        $this->subscribe($connection, 'orders');
        $this->send($connection, 'pusher:unsubscribe', ['channel' => 'orders']);

        self::assertFalse($connection->hasJoined('orders'));
    }

    public function test_a_channel_is_forgotten_once_the_last_subscriber_leaves(): void
    {
        // Otherwise the map grows for the life of the process: a channel per
        // conversation means every conversation ever opened, empty, forever.
        $connection = $this->connect();

        $this->subscribe($connection, 'orders');
        self::assertNotNull($this->channels->existing('demo', 'orders'));

        $this->send($connection, 'pusher:unsubscribe', ['channel' => 'orders']);
        self::assertNull($this->channels->existing('demo', 'orders'));
    }

    public function test_subscribing_twice_is_not_an_error(): void
    {
        // Clients resubscribe on reconnect, and a reconnect racing the old
        // socket's cleanup is normal rather than a fault.
        $connection = $this->connect();

        $this->subscribe($connection, 'orders');
        $this->drain($connection);

        $this->subscribe($connection, 'orders');

        self::assertSame([], $this->sent($connection));
        self::assertTrue($connection->hasJoined('orders'));
    }

    // ── protocol basics ──────────────────────────────────────────────────

    public function test_ping_is_answered_with_pong(): void
    {
        $connection = $this->connect();

        $this->send($connection, 'pusher:ping');

        self::assertSame('pusher:pong', $this->lastEvent($connection));
    }

    public function test_an_unknown_event_is_reported_rather_than_ignored(): void
    {
        // Silence leaves a client unable to tell a rejected message from a
        // lost one.
        $connection = $this->connect();

        $this->send($connection, 'pusher:teleport');

        self::assertSame('pusher:error', $this->lastEvent($connection));
    }

    public function test_a_malformed_frame_is_reported(): void
    {
        $connection = $this->connect();

        $this->handler->handle($connection, 'not json at all');

        self::assertSame('pusher:error', $this->lastEvent($connection));
    }

    public function test_data_may_arrive_as_a_json_string(): void
    {
        // The specification allows both, and client libraries differ.
        $connection = $this->connect();

        $this->handler->handle($connection, (string) json_encode([
            'event' => 'pusher:subscribe',
            'data' => json_encode(['channel' => 'orders']),
        ]));

        self::assertTrue($connection->hasJoined('orders'));
    }
}
