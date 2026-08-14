<?php

declare(strict_types=1);

namespace LibxaSocket\Console\Commands;

use Libxa\Foundation\Application;
use LibxaSocket\Server\RestartSignal;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ask a running server to stop, so a supervisor starts it again on new code.
 *
 * The server holds every connection and every channel in memory, and loads
 * your code once at boot. Deploying does not reach it: until it restarts it
 * keeps running the code it started with, which is the standard way for a
 * deploy to appear to have not worked.
 *
 * This writes a timestamp the running server watches. It stops cleanly, and
 * whatever supervises it — systemd, supervisord, Docker's restart policy —
 * brings it back. On its own it stops the server and does not start it.
 */
class RestartCommand extends Command
{
    protected static $defaultName = 'socket:restart';

    public function __construct(protected Application $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('socket:restart')
             ->setDescription('Signal a running socket server to stop so it restarts on new code');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $signal = new RestartSignal($this->app->storagePath('framework/socket-restart'));

        if (! $signal->write()) {
            $output->writeln('<error>Could not write the restart signal to ' . $signal->path() . '</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Restart signalled.</info>');
        $output->writeln('A running server will stop within a few seconds. Whatever supervises it should start it again.');

        return Command::SUCCESS;
    }
}
