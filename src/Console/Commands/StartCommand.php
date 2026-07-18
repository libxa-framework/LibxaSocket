<?php

declare(strict_types=1);

namespace LibxaSocket\Console\Commands;

use LibxaSocket\ApplicationManager;
use LibxaSocket\Server;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Libxa\Foundation\Application;

/**
 * Start the LibxaSocket WebSocket server.
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
            ->addOption('host', null, InputOption::VALUE_OPTIONAL, 'The host to bind to', env('SOCKET_HOST', env('WS_HOST', '0.0.0.0')))
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'The port to listen on', (int) env('SOCKET_PORT', env('WS_PORT', 8080)))
            ->addOption('workers', null, InputOption::VALUE_OPTIONAL, 'Number of worker processes', (int) env('SOCKET_WORKERS', env('WS_WORKERS', 4)))
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Log every connection/message event (noisy, dev only)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $host    = $input->getOption('host');
        $port    = (int) $input->getOption('port');
        $workers = (int) $input->getOption('workers');

        $isWindows = DIRECTORY_SEPARATOR === '\\';

        $io->title('LibxaSocket Server');

        $manager = new ApplicationManager($this->app);
        $apps    = $manager->all();

        if (empty($apps)) {
            $io->warning('No apps configured in config/socket.php — run `php Libxa socket:install` first.');
        } else {
            $io->section('Registered apps');
            foreach ($apps as $app) {
                $io->writeln("  <info>{$app->id}</info>  key=<comment>{$app->key}</comment>");
            }
            $io->newLine();
        }

        if ($isWindows && $workers > 1) {
            $io->info("Starting server on {$host}:{$port}...");
            $io->note("Windows only supports a single Workerman process — the --workers={$workers} option will be ignored.");
        } else {
            $io->info("Starting server on {$host}:{$port} with {$workers} workers...");
        }

        $server = new Server($host, $port, $workers, $this->app);

        try {
            $server->start();
        } catch (\Throwable $e) {
            return $this->reportBindFailure($io, $host, $port, $e);
        }

        return Command::SUCCESS;
    }

    /**
     * Translate a low-level socket bind failure into actionable guidance
     * instead of a raw Workerman stack trace. Particularly common on
     * Windows, where "Unable to connect to tcp://..." plus a permissions
     * message is WSAEACCES (10013) — almost never a code bug, but one of:
     * a port already bound by something else, a Windows port-exclusion
     * range (Hyper-V/WSL2), or a firewall/antivirus blocking the bind.
     */
    protected function reportBindFailure(SymfonyStyle $io, string $host, int $port, \Throwable $e): int
    {
        $message = $e->getMessage();
        $isBindFailure = str_contains($message, 'Unable to connect to')
            || str_contains($message, 'Address already in use')
            || str_contains($message, "d'accès à un socket");

        if (! $isBindFailure) {
            $io->error("Socket server failed to start: {$message}");
            return Command::FAILURE;
        }

        $isWindows = DIRECTORY_SEPARATOR === '\\';

        $io->error("Could not bind to {$host}:{$port} — the OS refused the socket.");

        $tips = [
            "Something else may already be listening on port {$port}. Try a different port: php Libxa socket:start --port=8090",
        ];

        if ($isWindows) {
            $tips[] = "Check if the port falls in a Windows-reserved range (common with Hyper-V / WSL2): netsh interface ipv4 show excludedportrange protocol=tcp";
            $tips[] = "If it does, just pick a port outside those ranges — that's the simplest fix.";
            $tips[] = "A firewall or antivirus may be blocking the bind — try running the terminal as Administrator, or temporarily disable it to confirm.";
        } else {
            $tips[] = "On Linux/macOS, binding to ports below 1024 requires root. {$port} shouldn't need that, but if you changed it, keep it above 1024 or run with sudo.";
            $tips[] = "Check what's using the port: lsof -i :{$port}";
        }

        $io->listing($tips);

        return Command::FAILURE;
    }
}
