<?php

declare(strict_types=1);

/**
 * LibxaSocket configuration.
 *
 * The package's fallback — run `php libxa socket:install` to publish a copy
 * with a freshly generated key and secret into your application.
 */
return [

    /*
     * Where the server listens.
     *
     * 0.0.0.0 accepts connections from anywhere the machine is reachable. In
     * production that should be behind a reverse proxy terminating TLS, or
     * bound to 127.0.0.1 with the proxy on the same host: this server speaks
     * ws://, not wss://, and a browser on an https:// page will refuse a
     * ws:// connection outright.
     */
    'server' => [
        'host' => env('SOCKET_HOST', '0.0.0.0'),
        'port' => (int) env('SOCKET_PORT', 8080),
    ],

    /*
     * Where your application reaches the server to publish events.
     *
     * Separate from 'server' on purpose: the server may bind 0.0.0.0 while
     * your application talks to it on 127.0.0.1, and in a container they are
     * different hosts entirely.
     */
    'client' => [
        'host' => env('SOCKET_CLIENT_HOST', '127.0.0.1'),
        'port' => (int) env('SOCKET_CLIENT_PORT', env('SOCKET_PORT', 8080)),
        'tls' => (bool) env('SOCKET_CLIENT_TLS', false),
        'timeout' => (float) env('SOCKET_CLIENT_TIMEOUT', 2.0),
    ],

    /*
     * Whether browsers may send `client-*` events directly to each other.
     *
     * Only ever on private and presence channels, and only between clients
     * already authorized to be there. Useful for typing indicators and
     * cursors — things not worth a round trip through your application. Turn
     * it off if you would rather every message be something your server saw.
     */
    'client_events' => (bool) env('SOCKET_CLIENT_EVENTS', true),

    /*
     * Which application to publish as, when more than one is registered.
     * With exactly one, this can stay empty.
     */
    'default' => env('SOCKET_APP_ID', ''),

    /*
     * The registered applications.
     *
     * The key is public: it ships in your JavaScript and identifies which
     * application a browser is connecting to. The secret never leaves the
     * server — it signs channel authorizations and the publishing API, and
     * anyone holding it can send any message to any of your users.
     */
    'apps' => [
        [
            'id' => env('SOCKET_APP_ID', 'libxa'),
            'key' => env('SOCKET_APP_KEY', 'libxa-key'),
            'secret' => env('SOCKET_APP_SECRET', 'libxa-secret'),
            'options' => [],
        ],
    ],

];
