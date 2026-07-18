<?php

declare(strict_types=1);

namespace LibxaSocket\Console\Commands;

use LibxaSocket\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Libxa\Foundation\Application;

/**
 * Signal all running `socket:start` workers to restart. Useful after
 * deploying new channel classes, since those are only scanned once at
 * worker boot.
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
             ->setDescription('Signal all running socket:start workers to restart');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $signalFile = (new Server())->restartSignalPath();

        $directory = dirname($signalFile);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($signalFile, (string) microtime(true));

        $output->writeln('<info>Broadcasting socket server restart signal.</info>');

        return Command::SUCCESS;
    }
}
