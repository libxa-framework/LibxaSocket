<?php

declare(strict_types=1);

namespace LibxaSocket\Console\Commands;

use Libxa\Foundation\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Set an application up for realtime.
 *
 * Publishes the config, generates a key and secret, writes them to .env, and
 * scaffolds a channels file — the last of which matters most: without it every
 * private and presence channel is refused, which is correct and looks broken.
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
             ->setDescription('Publish the socket config, generate credentials and scaffold a channels file')
             ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing config and credentials');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool) $input->getOption('force');

        $io->title('LibxaSocket');

        $this->publishConfig($io, $force);
        $credentials = $this->writeEnv($io, $force);
        $this->scaffoldChannels($io);

        $io->success('Installed.');

        $io->section('Next');
        $io->listing([
            'Start the server:  php libxa socket:start',
            'Set BROADCAST_DRIVER=socket in your .env so broadcast() publishes through it.',
            'Point Laravel Echo at it — see the README for the six lines of JavaScript.',
        ]);

        if ($credentials !== null) {
            $io->text('Your app key is public and belongs in your JavaScript. The secret does not:');
            $io->newLine();
            $io->text("  SOCKET_APP_KEY={$credentials['key']}");
            $io->text('  SOCKET_APP_SECRET=' . str_repeat('*', 16) . ' (written to .env)');
            $io->newLine();
        }

        return Command::SUCCESS;
    }

    private function publishConfig(SymfonyStyle $io, bool $force): void
    {
        $target = $this->app->basePath('src/config/socket.php');

        if (is_file($target) && ! $force) {
            $io->text('config/socket.php already exists, leaving it alone.');

            return;
        }

        $directory = dirname($target);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        copy(__DIR__ . '/../../../config/socket.php', $target);

        $io->text('Published config/socket.php');
    }

    /**
     * @return array{key: string, secret: string}|null
     */
    private function writeEnv(SymfonyStyle $io, bool $force): ?array
    {
        $path = $this->app->basePath('.env');

        if (! is_file($path)) {
            $io->warning('No .env file, so no credentials were written. Add SOCKET_APP_* by hand.');

            return null;
        }

        $env = (string) file_get_contents($path);

        if (str_contains($env, 'SOCKET_APP_SECRET=') && ! $force) {
            $io->text('.env already has socket credentials, leaving them alone.');

            return null;
        }

        $credentials = [
            'id' => 'app-' . bin2hex(random_bytes(4)),
            'key' => bin2hex(random_bytes(16)),
            // The secret signs channel authorizations and the publishing API.
            // Anyone holding it can send any message to any of your users, so
            // it is generated rather than left as a memorable default.
            'secret' => bin2hex(random_bytes(32)),
        ];

        $block = <<<ENV

        SOCKET_APP_ID={$credentials['id']}
        SOCKET_APP_KEY={$credentials['key']}
        SOCKET_APP_SECRET={$credentials['secret']}
        SOCKET_HOST=0.0.0.0
        SOCKET_PORT=8080
        SOCKET_CLIENT_HOST=127.0.0.1
        ENV;

        $env = preg_replace('/^SOCKET_[A-Z_]+=.*$\n?/m', '', $env) ?? $env;

        file_put_contents($path, rtrim($env) . "\n" . $block . "\n");

        $io->text('Wrote credentials to .env');

        return ['key' => $credentials['key'], 'secret' => $credentials['secret']];
    }

    private function scaffoldChannels(SymfonyStyle $io): void
    {
        $path = $this->app->basePath('src/routes/channels.php');

        if (is_file($path)) {
            $io->text('routes/channels.php already exists, leaving it alone.');

            return;
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, <<<'PHP'
        <?php

        /**
         * Who may listen to what.
         *
         * A private or presence channel with no rule here is refused. That is
         * deliberate: the alternative allows what nobody has thought about,
         * which makes every channel public until somebody remembers it exists.
         *
         * @var \LibxaSocket\Channels\ChannelGate $channel
         */

        // Only the owner of an order may watch it.
        $channel->register('orders.{orderId}', function ($user, string $orderId): bool {
            return (string) $user->id === $orderId;
        });

        // Presence: return the profile the rest of the room should see. Whatever
        // this returns is visible to everyone else in the channel, so leave out
        // anything you would not put on a public page.
        $channel->register('room.{roomId}', function ($user, string $roomId): array {
            return [
                'user_id' => (string) $user->id,
                'user_info' => ['name' => $user->name],
            ];
        });
        PHP);

        $io->text('Scaffolded routes/channels.php');
    }
}
