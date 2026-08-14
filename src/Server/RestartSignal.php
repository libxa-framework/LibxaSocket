<?php

declare(strict_types=1);

namespace LibxaSocket\Server;

/**
 * A timestamp a running server watches so it knows to stop.
 *
 * A file rather than a signal, because the process doing the deploying is
 * rarely the one that can signal the server: a deploy script, a container
 * build step and a CI runner all share the filesystem and none of them has the
 * server's pid.
 */
class RestartSignal
{
    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function write(): bool
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return false;
        }

        return @file_put_contents($this->path, (string) microtime(true)) !== false;
    }

    /**
     * What the signal said at a point in time, or null if never written.
     *
     * The server reads this once at boot and compares periodically. Comparing
     * against "now" instead would restart the server every time it started,
     * whenever a signal had ever been written.
     */
    public function read(): ?string
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = @file_get_contents($this->path);

        return $contents === false ? null : $contents;
    }
}
