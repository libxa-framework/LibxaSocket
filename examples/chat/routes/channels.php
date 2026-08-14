<?php

/**
 * Who may listen to what.
 *
 * A private or presence channel with no rule here is refused. That is
 * deliberate: the alternative allows what nobody has thought about, which
 * makes every channel public until somebody remembers it exists.
 *
 * @var \LibxaSocket\Channels\ChannelGate $channel
 */

/*
 * This demo has no login, so identity comes from the session instead of the
 * auth guard. A real application deletes this and gets `auth()->user()`.
 */
$channel->resolveUserUsing(function () {
    return session()?->get('demo_user');
});

/*
 * The chat rooms.
 *
 * This demo lets anyone into any room, and returns the identity the rest of
 * the room will see. In a real application this is where you would check that
 * the user is a member of the room — and note that whatever is returned is
 * visible to everyone else in it, so it should carry a display name and
 * nothing more.
 */
$channel->register('room.{room}', function ($user, string $room): array {
    return [
        'user_id' => (string) ($user['id'] ?? $user->id ?? '0'),
        'user_info' => ['name' => (string) ($user['name'] ?? $user->name ?? 'Guest')],
    ];
});
