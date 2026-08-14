<?php

declare(strict_types=1);

namespace LibxaSocket\Console\Commands;

use Libxa\Foundation\Application;
use LibxaSocket\ApplicationManager;
use LibxaSocket\Logging\CliLogger;
use LibxaSocket\Server\Reactor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Start the WebSocket server.
 */
class StartCommand extends Command
{
    protected static $defaultName = 'socket:start';

    public function __construct(protected Application $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('socket:start')
            ->setDescription('Start the LibxaSocket WebSocket server')
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'The address to bind to')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'The port to listen on')
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Log every connection and message (noisy, development only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('LibxaSocket');

        $manager = ApplicationManager::fromFramework($this->app);

        if ($manager->isEmpty()) {
            $io->error('No applications are configured.');
            $io->text('Run php libxa socket:install, or add one to config/socket.php.');

            return Command::FAILURE;
        }

        $host = (string) ($input->getOption('host') ?? $this->app->config('socket.server.host') ?? '0.0.0.0');
        $port = (int) ($input->getOption('port') ?? $this->app->config('socket.server.port') ?? 8080);

        $logger = new CliLogger($output, (bool) $input->getOption('debug'));

        $rows = [];

        foreach ($manager->all() as $application) {
            $rows[] = [$application->id, $application->key, 'ws://' . ($host === '0.0.0.0' ? '127.0.0.1' : $host) . ":{$port}/app/{$application->key}"];
        }

        $io->table(['App', 'Key', 'Connect to'], $rows);

        $reactor = new Reactor(
            applications: $manager,
            logger: $logger,
            allowClientEvents: (bool) ($this->app->config('socket.client_events') ?? true),
        );

        $reactor->watchForRestart(
            new \LibxaSocket\Server\RestartSignal($this->app->storagePath('framework/socket-restart')),
        );

        // Ctrl+C, and the signal a supervisor sends on stop. Without these the
        // loop keeps the process alive and the shell appears to hang.
        $this->trapSignals($reactor, $io);

        $io->text('Press Ctrl+C to stop.');
        $io->newLine();

        try {
            $reactor->start($host, $port);
        } catch (\Throwable $e) {
            return $this->explainFailure($io, $e, $host, $port);
        }

        return Command::SUCCESS;
    }

    /**
     * Stop cleanly on Ctrl+C and SIGTERM.
     *
     * pcntl is not available on Windows, and not always compiled in
     * elsewhere; without it Ctrl+C still terminates the process, just without
     * the closing message.
     */
    private function trapSignals(Reactor $reactor, SymfonyStyle $io): void
    {
        if (! function_exists('pcntl_signal') || ! defined('SIGINT')) {
            return;
        }

        $stop = function () use ($reactor, $io): void {
            $io->newLine();
            $io->text('Stopping.');

            $reactor->stop();
        };

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, $stop);
        pcntl_signal(SIGTERM, $stop);
    }

    /**
     * Say what to do about a failure to bind.
     *
     * The raw exception is "Failed to listen on tcp://...", which is true and
     * unhelpful. These are the three things it actually is.
     */
    private function explainFailure(SymfonyStyle $io, \Throwable $e, string $host, int $port): int
    {
        $io->error($e->getMessage());

        $io->text([
            "Could not listen on {$host}:{$port}. Usually one of:",
            '',
            '  1. Something else is already on that port. Try --port=8081.',
            '  2. The port needs privileges (anything below 1024 on Linux and macOS).',
        ]);

        if (DIRECTORY_SEPARATOR === '\\') {
            $io->text([
                '  3. On Windows, Hyper-V and WSL2 reserve port ranges, and a port inside',
                '     one cannot be bound even though nothing is using it. Check with:',
                '',
                '       netsh interface ipv4 show excludedportrange protocol=tcp',
            ]);
        }

        return Command::FAILURE;
    }
}
