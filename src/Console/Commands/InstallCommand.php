<?php

declare(strict_types=1);

namespace LibxaSocket\Console\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Libxa\Foundation\Application;

/**
 * Installs and scaffolds the LibxaSocket ecosystem: config, .env entries,
 * an example channel, and a small demo page (all wired through a
 * controller, not a closure — so `route:cache` keeps working).
 */
class InstallCommand extends Command
{
    protected static $defaultName = 'socket:install';

    public function __construct(protected Application $app)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('socket:install')
             ->setDescription('Install and scaffold the LibxaSocket WebSocket server');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('LibxaSocket Installer');

        $this->publishConfig($io);
        $this->setupEnvironment($io);
        $this->scaffoldDirectories($io);
        $this->scaffoldChannel($io);
        $this->scaffoldDemoPage($io);
        $this->registerRoute($io);

        $io->success('LibxaSocket installed successfully!');
        $io->info('To try it out:');
        $io->listing([
            'php Libxa socket:start',
            'php Libxa serve',
            'Visit: http://localhost:8000/socket-test (adjust the port to your `serve` output)',
        ]);

        return Command::SUCCESS;
    }

    protected function publishConfig(SymfonyStyle $io): void
    {
        $target = $this->app->configPath('socket.php');

        if (file_exists($target)) {
            $io->comment('config/socket.php already exists, skipping.');
            return;
        }

        $appId     = 'libxa';
        $appKey    = bin2hex(random_bytes(16));
        $appSecret = bin2hex(random_bytes(32));

        $stub = <<<PHP
<?php

declare(strict_types=1);

/**
 * LibxaSocket configuration.
 *
 * Every WebSocket connection is scoped to one of the "apps" below (matched
 * by id). Private/presence channel subscriptions are authorized using that
 * app's secret — never expose it to the browser, only the key.
 */
return [

    'apps' => [
        [
            'id'      => env('SOCKET_APP_ID', '{$appId}'),
            'key'     => env('SOCKET_APP_KEY', '{$appKey}'),
            'secret'  => env('SOCKET_APP_SECRET', '{$appSecret}'),
            'options' => [
                'host' => env('SOCKET_HOST', '0.0.0.0'),
                'port' => (int) env('SOCKET_PORT', 8080),
            ],
        ],
    ],

];
PHP;

        $directory = dirname($target);
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($target, $stub);
        $io->comment('Published config/socket.php');
    }

    protected function setupEnvironment(SymfonyStyle $io): void
    {
        $envFile = $this->app->basePath('.env');
        if (! file_exists($envFile)) {
            return;
        }

        $content  = file_get_contents($envFile);
        $modified = false;

        if (! str_contains($content, 'SOCKET_PORT')) {
            $content .= "\n# LibxaSocket (Workerman WebSocket server)\nSOCKET_HOST=0.0.0.0\nSOCKET_PORT=8080\nSOCKET_WORKERS=4\n";
            $modified = true;
        }

        if ($modified) {
            file_put_contents($envFile, $content);
            $io->comment('Added SOCKET_* variables to .env');
        }
    }

    protected function scaffoldDirectories(SymfonyStyle $io): void
    {
        $dir = $this->app->basePath('src/app/WebSockets');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
            $io->comment('Created src/app/WebSockets directory');
        }
    }

    protected function scaffoldChannel(SymfonyStyle $io): void
    {
        $file = $this->app->basePath('src/app/WebSockets/RandomChannel.php');
        if (file_exists($file)) {
            return;
        }

        $content = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\WebSockets;

use LibxaSocket\WsChannel;
use LibxaSocket\WsConnection;
use LibxaSocket\Attributes\WsRoute;
use LibxaSocket\Attributes\OnEvent;

#[WsRoute('/ws/random')]
class RandomChannel extends WsChannel
{
    public function onOpen(WsConnection $connection): void
    {
        $connection->join('random-stream');

        $connection->send([
            'event'   => 'connected',
            'message' => 'You are now receiving random numbers from the server!',
        ]);
    }

    #[OnEvent('ping')]
    public function handlePing(WsConnection $connection): void
    {
        $connection->send(['event' => 'pong', 'time' => time()]);
    }
}
PHP;

        file_put_contents($file, $content);
        $io->comment('Scaffolded App\WebSockets\RandomChannel');
    }

    protected function scaffoldDemoPage(SymfonyStyle $io): void
    {
        $controllerFile = $this->app->basePath('src/app/Http/Controllers/SocketTestController.php');

        if (! file_exists($controllerFile)) {
            $controllerStub = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Libxa\Http\Response;

class SocketTestController
{
    public function index(): Response
    {
        return view('socket-test', [
            'port' => (int) env('SOCKET_PORT', 8080),
        ]);
    }
}
PHP;

            $directory = dirname($controllerFile);
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            file_put_contents($controllerFile, $controllerStub);
            $io->comment('Scaffolded App\Http\Controllers\SocketTestController');
        }

        $viewDir = $this->app->basePath('src/resources/views');
        if (! is_dir($viewDir)) {
            mkdir($viewDir, 0755, true);
        }

        $viewFile = $viewDir . '/socket-test.blade.php';
        if (file_exists($viewFile)) {
            return;
        }

        $content = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LibxaSocket Test</title>
    <style>
        :root { --bg: #0f172a; --card: #1e293b; --primary: #6366f1; --text: #f8fafc; }
        body { margin: 0; font-family: system-ui, sans-serif; background: var(--bg); color: var(--text);
               display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { background: var(--card); border-radius: 1.5rem; padding: 2rem; max-width: 420px;
                border: 1px solid rgba(255,255,255,0.1); text-align: center; }
        h1 { margin: 0 0 .5rem; font-size: 1.4rem; }
        p { color: #94a3b8; font-size: .875rem; margin-bottom: 1.5rem; }
        .value { font-family: monospace; font-size: 1.5rem; color: var(--primary); margin-bottom: 1rem; }
        .badge { display: inline-block; padding: .25rem .75rem; border-radius: 999px; font-size: .75rem;
                 font-weight: 600; background: rgba(239,68,68,.15); color: #f87171; margin-bottom: 1rem; }
        .badge.on { background: rgba(34,197,94,.15); color: #4ade80; }
        .log { background: rgba(0,0,0,.3); border-radius: .75rem; padding: 1rem; text-align: left;
               font-family: monospace; font-size: .75rem; height: 100px; overflow-y: auto; color: #94a3b8; }
    </style>
</head>
<body>
    <div class="card">
        <div id="status" class="badge">Disconnected</div>
        <h1>LibxaSocket Test</h1>
        <p>Live stream from the RandomChannel example</p>
        <div class="value" id="value">--</div>
        <div class="log" id="log"><div>[System] Connecting...</div></div>
    </div>

    <script>
        const logEl = document.getElementById('log');
        const statusEl = document.getElementById('status');
        const valueEl = document.getElementById('value');

        function log(msg) {
            const div = document.createElement('div');
            div.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
            logEl.prepend(div);
        }

        const socket = new WebSocket(`ws://${window.location.hostname}:{{ $port }}/ws/random`);

        socket.onopen = () => {
            statusEl.textContent = 'Online';
            statusEl.classList.add('on');
            log('Connected');
        };

        socket.onmessage = (event) => {
            const data = JSON.parse(event.data);
            if (data.event === 'random.number') {
                valueEl.textContent = data.data.value;
                log(`Pulse: ${data.data.value}`);
            } else {
                log(JSON.stringify(data));
            }
        };

        socket.onclose = () => {
            statusEl.textContent = 'Disconnected';
            statusEl.classList.remove('on');
            log('Disconnected');
        };
    </script>
</body>
</html>
HTML;

        file_put_contents($viewFile, $content);
        $io->comment('Scaffolded src/resources/views/socket-test.blade.php');
    }

    protected function registerRoute(SymfonyStyle $io): void
    {
        $routeFile = $this->app->basePath('src/routes/web.php');
        if (! file_exists($routeFile)) {
            return;
        }

        $content = file_get_contents($routeFile);

        if (str_contains($content, '/socket-test')) {
            return;
        }

        if (! str_contains($content, 'App\Http\Controllers\SocketTestController')) {
            $content = preg_replace(
                '/^(use App\\\\Http\\\\Controllers\\\\WelcomeController;)/m',
                "$1\nuse App\\Http\\Controllers\\SocketTestController;",
                $content,
                1
            ) ?? $content;
        }

        $content .= "\n// LibxaSocket demo page\n\$router->get('/socket-test', [SocketTestController::class, 'index']);\n";

        file_put_contents($routeFile, $content);
        $io->comment('Registered /socket-test route (controller-based, route:cache-safe)');
    }
}
