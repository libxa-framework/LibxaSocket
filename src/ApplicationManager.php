<?php

declare(strict_types=1);

namespace LibxaSocket;

use Libxa\Foundation\Application as LibxaApplication;

/**
 * The registered applications.
 *
 * Resolved by key when a browser connects to `/app/{key}` — the key is public,
 * it ships in your JavaScript — and by id for the signed HTTP API, where the
 * secret behind it is what actually proves the caller.
 */
class ApplicationManager
{
    /** @var array<string, Application> */
    protected array $apps = [];

    /**
     * @param array<int, array<string, mixed>> $definitions
     */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $definition) {
            $instance = Application::fromArray($definition);
            $this->apps[$instance->id] = $instance;
        }
    }

    /**
     * Build from a booted framework application's config.
     *
     * Separate from the constructor so the server, and its tests, can run
     * without a framework instance — the protocol does not need one, and a
     * constructor that demanded one made every test boot an application to
     * check a signature.
     */
    public static function fromFramework(LibxaApplication $app): self
    {
        return new self((array) $app->config('socket.apps', []));
    }

    public function findById(string $id): ?Application
    {
        return $this->apps[$id] ?? null;
    }

    public function findByKey(string $key): ?Application
    {
        foreach ($this->apps as $app) {
            if (hash_equals($app->key, $key)) {
                return $app;
            }
        }

        return null;
    }

    /** @return array<string, Application> */
    public function all(): array
    {
        return $this->apps;
    }

    public function isEmpty(): bool
    {
        return $this->apps === [];
    }
}
