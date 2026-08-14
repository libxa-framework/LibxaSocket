<?php

declare(strict_types=1);

namespace LibxaSocket\Channels;

use Libxa\Http\Request;

/**
 * Who may listen to what.
 *
 * Applications register a callback per channel, and the callback decides. The
 * shape is Laravel's, because it is the right shape: the channel name can
 * carry a parameter, and the callback receives the authenticated user plus
 * whatever the name matched.
 *
 *     Channel::private('orders.{orderId}', fn ($user, $orderId) =>
 *         Order::find($orderId)?->user_id === $user->id);
 *
 *     Channel::presence('room.{roomId}', fn ($user, $roomId) =>
 *         ['user_id' => $user->id, 'user_info' => ['name' => $user->name]]);
 *
 * A channel with no registered callback is refused. The alternative — allowing
 * what nobody has thought about — makes every private channel public until
 * somebody remembers it exists.
 */
class ChannelGate
{
    /** @var array<string, \Closure> pattern => callback */
    private array $callbacks = [];

    /** How to find who is asking. Defaults to the authenticated user. */
    private ?\Closure $userResolver = null;

    public function register(string $pattern, \Closure $callback): void
    {
        $this->callbacks[$this->normalise($pattern)] = $callback;
    }

    /**
     * Change how the current user is identified.
     *
     * The default is `auth()->user()`, which is right for most applications
     * and wrong for the ones whose realtime identity is not their login — an
     * anonymous support chat keyed on a session, a device rather than a
     * person. Without this those applications cannot use presence channels at
     * all, since every subscription would be refused for want of a user.
     */
    public function resolveUserUsing(\Closure $resolver): void
    {
        $this->userResolver = $resolver;
    }

    public function has(string $channel): bool
    {
        return $this->match($this->normalise($channel)) !== null;
    }

    /**
     * Decide whether the request may join the channel.
     *
     * @return array|string|true|null null when refused; for presence channels
     *         an array describing the member, otherwise anything truthy
     */
    public function authorize(Request $request, string $channel): array|string|bool|null
    {
        $name = $this->normalise($channel);

        $matched = $this->match($name);

        if ($matched === null) {
            return null;
        }

        [$callback, $parameters] = $matched;

        $user = $this->user();

        // No authenticated user means no. Every channel this gate protects is
        // scoped to somebody; a guest cannot be that somebody.
        if ($user === null) {
            return null;
        }

        $result = $callback($user, ...$parameters);

        if ($result === false || $result === null) {
            return null;
        }

        return $result === true ? true : $result;
    }

    /**
     * Find the callback whose pattern matches, and what it captured.
     *
     * @return array{0: \Closure, 1: list<string>}|null
     */
    private function match(string $channel): ?array
    {
        // An exact registration wins over a pattern, so a specific channel can
        // be given rules of its own without the general one shadowing it.
        if (isset($this->callbacks[$channel])) {
            return [$this->callbacks[$channel], []];
        }

        foreach ($this->callbacks as $pattern => $callback) {
            if (! str_contains($pattern, '{')) {
                continue;
            }

            $regex = '#^' . preg_replace('/\\\{[A-Za-z_][A-Za-z0-9_]*\\\}/', '([^.]+)', preg_quote($pattern, '#')) . '$#';

            if (preg_match($regex, $channel, $matches) === 1) {
                array_shift($matches);

                return [$callback, array_values($matches)];
            }
        }

        return null;
    }

    /**
     * The channel name without its protocol prefix.
     *
     * So a callback is registered once as `orders.{id}` and covers both
     * `private-orders.1` and `presence-orders.1` — which is what people expect,
     * and forgetting the prefix is otherwise a silent refusal.
     */
    private function normalise(string $channel): string
    {
        foreach (['private-encrypted-', 'private-', 'presence-'] as $prefix) {
            if (str_starts_with($channel, $prefix)) {
                return substr($channel, strlen($prefix));
            }
        }

        return $channel;
    }

    private function user(): mixed
    {
        try {
            if ($this->userResolver !== null) {
                return ($this->userResolver)();
            }

            return function_exists('auth') ? auth()?->user() : null;
        } catch (\Throwable) {
            // A resolver that throws is a refusal, not a 500: this runs on
            // every subscription, and the safe answer is no.
            return null;
        }
    }
}
