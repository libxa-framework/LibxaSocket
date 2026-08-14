<?php

use Libxa\Router\Router;

/** @var Router $router */

/*
 * The channel authorization endpoint.
 *
 * Laravel Echo posts here before joining a private or presence channel, with
 * the browser's session, and expects a signature back. The path matches
 * Echo's default so no client configuration is needed.
 *
 * Session middleware, not the API stack: the whole point is that this runs as
 * the logged-in user.
 */
$router->post('/broadcasting/auth', [\LibxaSocket\Http\Controllers\ChannelAuthController::class, 'authorize'])
    ->name('socket.auth');
