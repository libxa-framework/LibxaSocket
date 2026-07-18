<?php

declare(strict_types=1);

namespace LibxaSocket;

/**
 * A registered application.
 *
 * Mirrors Reverb/Pusher's concept of an "app": every connection is scoped
 * to one app (by id, in the connection path `/app/{id}`), and every
 * private/presence channel subscription for that app is authorized using
 * the app's secret (see Auth\ChannelAuthenticator).
 */
class Application
{
    public function __construct(
        public readonly string $id,
        public readonly string $key,
        public readonly string $secret,
        public readonly array $options = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            id:      (string) $data['id'],
            key:     (string) $data['key'],
            secret:  (string) $data['secret'],
            options: (array) ($data['options'] ?? []),
        );
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }
}
