<?php

use Libxa\Router\Router;

/** @var Router $router */

/*
 * The channel authorization endpoint.
 *
 * A client posts here before joining a private or presence channel, with the
 * browser's session, and expects a signature back. The path is the one every
 * Pusher client defaults to, so no configuration is needed.
 *
 * Session middleware, not the API stack: the whole point is that this runs as
 * the logged-in user.
 */
$router->post('/broadcasting/auth', [\LibxaSocket\Http\Controllers\ChannelAuthController::class, 'authorize'])
    ->name('socket.auth');
