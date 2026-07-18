<?php

declare(strict_types=1);

namespace LibxaSocket;

/**
 * Represents an incoming WebSocket message frame.
 */
class WsMessage
{
    public function __construct(
        protected string $event,
        protected array $data = []
    ) {}

    public function event(): string
    {
        return $this->event;
    }

    public function data(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) return $this->data;
        return $this->data[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->data;
    }

    /**
     * Parse raw JSON data into a WsMessage object.
     */
    public static function parse(string $payload): ?self
    {
        $decoded = json_decode($payload, true);
        
        if (!is_array($decoded) || !isset($decoded['event'])) {
            return null;
        }

        return new self($decoded['event'], $decoded['data'] ?? []);
    }
}
