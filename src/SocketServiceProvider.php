<?php

declare(strict_types=1);

namespace LibxaSocket;

use Libxa\Container\ServiceProvider;
use LibxaSocket\Auth\ChannelAuthenticator;
use LibxaSocket\Broadcasting\SocketBroadcaster;

/**
 * Auto-registered via composer.json's `extra.Libxa.providers` once the package
 * is discovered (`php libxa package:discover`).
 *
 * The socket:* commands are not registered here: the framework's console
 * application scans every discovered package's src/Console/Commands directory,
 * so shipping the files is enough.
 */
class SocketServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/socket.php', 'socket');

        $this->app->singleton(ApplicationManager::class, function ($app) {
            return ApplicationManager::fromFramework($app);
        });

        $this->app->alias(ApplicationManager::class, 'socket.apps');

        $this->app->singleton(ChannelAuthenticator::class, fn () => new ChannelAuthenticator());
        $this->app->alias(ChannelAuthenticator::class, 'socket.auth');

        // Shared, because routes/channels.php registers into it at boot and a
        // fresh gate at authorization time would have no callbacks at all —
        // which refuses every private channel, correctly and uselessly.
        $this->app->singleton(\LibxaSocket\Channels\ChannelGate::class, fn () => new \LibxaSocket\Channels\ChannelGate());
        $this->app->alias(\LibxaSocket\Channels\ChannelGate::class, 'socket.channels');

        // The broadcaster your application publishes through. Bound rather
        // than newed at the call site so an application can rebind it — a test
        // that wants to assert on broadcasts should not have to run a server.
        $this->app->singleton('socket.broadcaster', function ($app) {
            $manager = $app->make(ApplicationManager::class);
            $application = $this->defaultApplication($manager);

            if ($application === null) {
                throw new \RuntimeException(
                    'No socket application is configured. Run php libxa socket:install, or add one to config/socket.php.',
                );
            }

            $config = (array) $app->config('socket', []);

            return new SocketBroadcaster(
                app: $application,
                host: (string) ($config['client']['host'] ?? $application->option('host', '127.0.0.1')),
                port: (int) ($config['client']['port'] ?? $application->option('port', 8080)),
                useTls: (bool) ($config['client']['tls'] ?? false),
                timeout: (float) ($config['client']['timeout'] ?? 2.0),
            );
        });

        $this->app->alias('socket.broadcaster', SocketBroadcaster::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/socket.php' => 'src/config/socket.php',
        ], 'socket-config');

        $this->loadRoutesFrom(__DIR__ . '/Routes/web.php');

        $this->loadChannelRoutes();

        $this->registerBroadcastDriver();
    }

    /**
     * Make `BROADCAST_DRIVER=socket` work.
     *
     * So an application publishes through `broadcast(new SomethingHappened)`
     * like any other event, and switching to or from realtime is one line of
     * .env rather than a change to every call site.
     */
    private function registerBroadcastDriver(): void
    {
        if (! $this->app->has('broadcast')) {
            return;
        }

        $manager = $this->app->make('broadcast');

        if (! method_exists($manager, 'extend')) {
            // Older framework. The broadcaster is still resolvable directly
            // as `socket.broadcaster`; only the driver name is unavailable.
            return;
        }

        $manager->extend('socket', function () {
            return $this->app->make('socket.broadcaster');
        });
    }

    /**
     * Load the application's own channel authorization rules.
     *
     * routes/channels.php if it exists; the package works without one, but
     * every private and presence channel is refused until it does, which is
     * the safe direction to fail.
     */
    private function loadChannelRoutes(): void
    {
        $path = $this->app->basePath('src/routes/channels.php');

        if (! is_file($path)) {
            return;
        }

        $channel = $this->app->make(\LibxaSocket\Channels\ChannelGate::class);

        (static function () use ($path, $channel): void {
            require $path;
        })();
    }

    /**
     * The application to publish as.
     *
     * The one named by `socket.default`, or the only one configured. With
     * several configured and no default named, this returns null rather than
     * guessing — publishing to the wrong application delivers your events to
     * someone else's users.
     */
    private function defaultApplication(ApplicationManager $manager): ?Application
    {
        $configured = (string) ($this->app->config('socket.default', '') ?? '');

        if ($configured !== '') {
            return $manager->findById($configured);
        }

        $all = $manager->all();

        return count($all) === 1 ? reset($all) : null;
    }
}
