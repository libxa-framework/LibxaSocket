<?php

declare(strict_types=1);

namespace LibxaSocket;

use Libxa\Foundation\Application;
use LibxaSocket\Attributes\WsRoute;
use ReflectionClass;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

/**
 * Routes WebSocket connections to the appropriate Channel class.
 */
class WsRouter
{
    /** @var array Map of URI patterns to Channel classes */
    protected array $channels = [];

    public function __construct(protected Application $app)
    {
    }

    /**
     * Register a channel manually.
     */
    public function channel(string $uri, string $channelClass): void
    {
        $this->channels[$uri] = $channelClass;
    }

    /**
     * Match a URI to a registered channel.
     * Returns [channelClass, parameters]
     */
    public function match(string $uri): ?array
    {
        $uri = '/' . trim($uri, '/');

        foreach ($this->channels as $pattern => $class) {
            $path = '/' . trim($pattern, '/');

            // Exact match
            if ($path === $uri) {
                return [$class, []];
            }

            // Pattern match (simplified version of Http Router matching)
            if (str_contains($path, '{')) {
                $regex = $this->compilePattern($path);
                if (preg_match($regex, $uri, $matches)) {
                    $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                    return [$class, $params];
                }
            }
        }

        return null;
    }

    /**
     * Scan a directory for Channel classes using #[WsRoute].
     */
    public function scanDirectory(string $directory, string $namespace): void
    {
        if (!is_dir($directory)) return;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') continue;

            $relative = str_replace($directory . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $class = $namespace . '\\' . str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);

            if (class_exists($class)) {
                $reflection = new ReflectionClass($class);
                foreach ($reflection->getAttributes(WsRoute::class) as $attr) {
                    $route = $attr->newInstance();
                    $this->channel($route->uri, $class);
                }
            }
        }
    }

    protected function compilePattern(string $uri): string
    {
        $pattern = preg_replace_callback(
            '/\{(\w+)(\?)?\}/',
            function (array $m): string {
                $name = $m[1];
                $optional = isset($m[2]) && $m[2] === '?';

                return $optional
                    ? "(?P<{$name}>[^/]+)?"
                    : "(?P<{$name}>[^/]+)";
            },
            $uri
        );

        return "#^{$pattern}$#";
    }

    public function getChannels(): array
    {
        return $this->channels;
    }
}
