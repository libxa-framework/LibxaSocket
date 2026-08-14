<?php

declare(strict_types=1);

namespace LibxaSocket\Tests;

/**
 * Presence channels: who else is here.
 *
 * The roster is the feature, and the thing that goes wrong with it is
 * counting connections instead of people. One user with two tabs is one
 * member; closing one tab is not leaving.
 */
final class PresenceTest extends ProtocolTestCase
{
    public function test_joining_returns_the_roster(): void
    {
        $connection = $this->connect();

        $this->joinPresence($connection, 'presence-room', '1', ['name' => 'Alice']);

        $message = $this->lastMessage($connection);

        self::assertSame('pusher_internal:subscription_succeeded', $message['event']);
        self::assertSame(1, $message['data']['presence']['count']);
        self::assertSame(['1'], $message['data']['presence']['ids']);
        self::assertSame(['name' => 'Alice'], $message['data']['presence']['hash']['1']);
    }

    public function test_a_joiner_sees_everyone_already_here(): void
    {
        $alice = $this->connect();
        $this->joinPresence($alice, 'presence-room', '1', ['name' => 'Alice']);

        $bob = $this->connect();
        $this->joinPresence($bob, 'presence-room', '2', ['name' => 'Bob']);

        $roster = $this->lastMessage($bob)['data']['presence'];

        self::assertSame(2, $roster['count']);
        self::assertEqualsCanonicalizing(['1', '2'], $roster['ids']);
    }

    public function test_everyone_else_is_told_somebody_arrived(): void
    {
        $alice = $this->connect();
        $this->joinPresence($alice, 'presence-room', '1');
        $this->drain($alice);

        $bob = $this->connect();
        $this->joinPresence($bob, 'presence-room', '2', ['name' => 'Bob']);

        $message = $this->lastMessage($alice);

        self::assertSame('pusher_internal:member_added', $message['event']);
        self::assertSame('2', $message['data']['user_id']);
        self::assertSame(['name' => 'Bob'], $message['data']['user_info']);
    }

    public function test_a_joiner_is_not_told_about_itself(): void
    {
        $alice = $this->connect();
        $this->joinPresence($alice, 'presence-room', '1');

        $events = array_column($this->sent($alice), 'event');

        self::assertNotContains('pusher_internal:member_added', $events);
    }

    public function test_leaving_tells_everyone_else(): void
    {
        $alice = $this->connect();
        $this->joinPresence($alice, 'presence-room', '1');

        $bob = $this->connect();
        $this->joinPresence($bob, 'presence-room', '2');
        $this->drain($bob);

        $this->send($alice, 'pusher:unsubscribe', ['channel' => 'presence-room']);

        $message = $this->lastMessage($bob);

        self::assertSame('pusher_internal:member_removed', $message['event']);
        self::assertSame('1', $message['data']['user_id']);
    }

    // ── one user, two connections ────────────────────────────────────────

    public function test_the_same_user_twice_counts_once(): void
    {
        $tabOne = $this->connect();
        $this->joinPresence($tabOne, 'presence-room', '1', ['name' => 'Alice']);

        $tabTwo = $this->connect();
        $this->joinPresence($tabTwo, 'presence-room', '1', ['name' => 'Alice']);

        self::assertSame(1, $this->lastMessage($tabTwo)['data']['presence']['count']);
    }

    public function test_a_second_connection_does_not_announce_an_arrival(): void
    {
        $observer = $this->connect();
        $this->joinPresence($observer, 'presence-room', '99');

        $tabOne = $this->connect();
        $this->joinPresence($tabOne, 'presence-room', '1');
        $this->drain($observer);

        $tabTwo = $this->connect();
        $this->joinPresence($tabTwo, 'presence-room', '1');

        self::assertSame([], $this->sent($observer), 'Opening a second tab is not arriving.');
    }

    public function test_closing_one_of_two_tabs_does_not_announce_a_departure(): void
    {
        $observer = $this->connect();
        $this->joinPresence($observer, 'presence-room', '99');

        $tabOne = $this->connect();
        $this->joinPresence($tabOne, 'presence-room', '1');

        $tabTwo = $this->connect();
        $this->joinPresence($tabTwo, 'presence-room', '1');
        $this->drain($observer);

        $this->send($tabOne, 'pusher:unsubscribe', ['channel' => 'presence-room']);

        self::assertSame([], $this->sent($observer), 'Still here on the other tab.');
    }

    public function test_closing_the_last_tab_does_announce_a_departure(): void
    {
        $observer = $this->connect();
        $this->joinPresence($observer, 'presence-room', '99');

        $tabOne = $this->connect();
        $this->joinPresence($tabOne, 'presence-room', '1');

        $tabTwo = $this->connect();
        $this->joinPresence($tabTwo, 'presence-room', '1');

        $this->send($tabOne, 'pusher:unsubscribe', ['channel' => 'presence-room']);
        $this->drain($observer);

        $this->send($tabTwo, 'pusher:unsubscribe', ['channel' => 'presence-room']);

        self::assertSame('pusher_internal:member_removed', $this->lastEvent($observer));
    }

    // ── channel data ─────────────────────────────────────────────────────

    public function test_a_presence_channel_requires_channel_data(): void
    {
        $connection = $this->connect();

        $this->send($connection, 'pusher:subscribe', [
            'channel' => 'presence-room',
            'auth' => $this->auth->authFor($this->app, $connection->id, 'presence-room'),
        ]);

        self::assertSame('pusher:error', $this->lastEvent($connection));
    }

    public function test_channel_data_is_verified_exactly_as_sent(): void
    {
        // The signature covers the JSON string, not the value. Re-encoding it
        // anywhere in the chain produces different bytes — different key
        // order, different escaping — and a correct signature fails.
        $connection = $this->connect();

        $signed = $this->auth->presenceAuth($this->app, $connection->id, 'presence-room', [
            'user_id' => '1',
            'user_info' => ['name' => 'Ada Lovelace', 'url' => 'https://example.com/a/b'],
        ]);

        $this->send($connection, 'pusher:subscribe', [
            'channel' => 'presence-room',
            'auth' => $signed['auth'],
            'channel_data' => $signed['channel_data'],
        ]);

        self::assertSame('pusher_internal:subscription_succeeded', $this->lastEvent($connection));
    }

    public function test_tampered_channel_data_is_refused(): void
    {
        // Otherwise anyone who can join a presence channel can join it as
        // somebody else.
        $connection = $this->connect();

        $signed = $this->auth->presenceAuth($this->app, $connection->id, 'presence-room', [
            'user_id' => '1',
            'user_info' => ['name' => 'Alice'],
        ]);

        $this->send($connection, 'pusher:subscribe', [
            'channel' => 'presence-room',
            'auth' => $signed['auth'],
            'channel_data' => (string) json_encode(['user_id' => '2', 'user_info' => ['name' => 'Admin']]),
        ]);

        self::assertSame('pusher:error', $this->lastEvent($connection));
    }
}
