<?php

declare(strict_types=1);

namespace LibxaSocket;

/**
 * Base class for typed WebSocket message DTOs.
 */
abstract class WsMessageDto
{
    /**
     * Map array data to public properties of the child class.
     */
    public static function fromArray(array $data): static
    {
        $reflection = new \ReflectionClass(static::class);
        $constructor = $reflection->getConstructor();

        if ($constructor) {
            return new static(...$data);
        }

        $instance = new static();
        foreach ($data as $key => $value) {
            if ($reflection->hasProperty($key)) {
                $instance->$key = $value;
            }
        }
        return $instance;
    }
}
