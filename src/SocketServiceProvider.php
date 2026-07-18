<?php

declare(strict_types=1);

namespace LibxaSocket;

use Libxa\Container\ServiceProvider;
use LibxaSocket\Auth\ChannelAuthenticator;
use LibxaSocket\Channels\PresenceRegistry;

/**
 * Auto-registered via composer.json's `extra.Libxa.providers` once the
 * package is discovered (`php Libxa package:discover`).
 *
 * Note: the socket:start / socket:restart / socket:install commands are
 * NOT registered here — the framework's console app auto-scans every
 * discovered package's src/Console/Commands/*.php directly (see
 * Console\Application::discoverCommands()), so simply shipping those files
 * there is enough.
 */
class SocketServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/socket.php', 'socket');

        $this->app->singleton(WsRouter::class, fn ($app) => new WsRouter($app));
        $this->app->singleton(ApplicationManager::class, fn ($app) => new ApplicationManager($app));
        $this->app->singleton(ChannelAuthenticator::class, fn () => new ChannelAuthenticator());
        $this->app->singleton(PresenceRegistry::class, fn () => new PresenceRegistry());
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/socket.php' => 'src/config/socket.php',
        ], 'config');
    }
}
