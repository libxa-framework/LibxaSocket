<?php

declare(strict_types=1);

namespace LibxaSocket;

use Libxa\Foundation\Application as LibxaApplication;

/**
 * Loads the registered app(s) from config/socket.php and resolves them by
 * id (used to validate incoming connections at `/app/{id}`) or by key
 * (used when signing/verifying REST trigger + channel-auth requests).
 */
class ApplicationManager
{
    /** @var Application[] */
    protected array $apps = [];

    public function __construct(LibxaApplication $app)
    {
        $configured = (array) $app->config('socket.apps', []);

        foreach ($configured as $definition) {
            $instance = Application::fromArray($definition);
            $this->apps[$instance->id] = $instance;
        }
    }

    public function findById(string $id): ?Application
    {
        return $this->apps[$id] ?? null;
    }

    public function findByKey(string $key): ?Application
    {
        foreach ($this->apps as $app) {
            if ($app->key === $key) {
                return $app;
            }
        }

        return null;
    }

    /**
     * @return Application[]
     */
    public function all(): array
    {
        return $this->apps;
    }
}
