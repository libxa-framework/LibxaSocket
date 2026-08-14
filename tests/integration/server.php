<?php

declare(strict_types=1);

/**
 * The server, standalone, for the round-trip check.
 *
 * No framework: the protocol does not need one, and requiring an application
 * here would make the integration test depend on a starter kit being present.
 */

require __DIR__ . '/../../vendor/autoload.php';

use LibxaSocket\ApplicationManager;
use LibxaSocket\Logging\CliLogger;
use LibxaSocket\Server\Reactor;
use Symfony\Component\Console\Output\ConsoleOutput;

$port = (int) ($argv[1] ?? 8123);

$reactor = new Reactor(
    applications: new ApplicationManager([
        ['id' => 'demo', 'key' => 'demo-key', 'secret' => 'demo-secret'],
    ]),
    logger: new CliLogger(new ConsoleOutput()),
);

$reactor->start('127.0.0.1', $port);
