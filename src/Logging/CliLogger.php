<?php

declare(strict_types=1);

namespace LibxaSocket\Logging;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * What the server prints.
 *
 * Debug output is off unless asked for: a busy server logging every frame
 * writes more than it serves, and on a terminal that is also the thing keeping
 * the process in the foreground.
 */
class CliLogger
{
    public function __construct(
        protected OutputInterface $output,
        protected bool $debug = false,
    ) {
    }

    public function info(string $message): void
    {
        $this->output->writeln("<info>{$message}</info>");
    }

    public function error(string $message): void
    {
        $this->output->writeln("<error>{$message}</error>");
    }

    public function warn(string $message): void
    {
        $this->output->writeln("<comment>{$message}</comment>");
    }

    public function debug(string $message): void
    {
        if (! $this->debug) {
            return;
        }

        $this->output->writeln('  <fg=gray>[' . date('H:i:s') . ']</> ' . $message);
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }
}
