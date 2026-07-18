<?php

declare(strict_types=1);

namespace LibxaSocket\Logging;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console-friendly logger for the socket server, used for the startup
 * banner and (optionally, with --debug) per-connection/message logging.
 */
class CliLogger
{
    public function __construct(protected SymfonyStyle $output, protected bool $debug = false)
    {
    }

    public function info(string $title, ?string $detail = null): void
    {
        $line = $detail !== null ? "<info>{$title}</info> {$detail}" : "<info>{$title}</info>";
        $this->output->writeln($line);
    }

    public function error(string $message): void
    {
        $this->output->writeln("<error>{$message}</error>");
    }

    public function warn(string $message): void
    {
        $this->output->writeln("<comment>{$message}</comment>");
    }

    /**
     * Log a connection lifecycle event (connect/disconnect/subscribe/...),
     * only when --debug is enabled — this can get noisy fast otherwise.
     */
    public function event(string $label, string $detail = ''): void
    {
        if (! $this->debug) {
            return;
        }

        $time = date('H:i:s');
        $this->output->writeln("  <fg=gray>[{$time}]</> <fg=cyan>{$label}</> {$detail}");
    }

    public function line(int $count = 1): void
    {
        $this->output->newLine($count);
    }
}
