<?php

declare(strict_types=1);

/**
 * LibxaSocket default configuration.
 *
 * This is the package's fallback — run `php Libxa socket:install` to
 * publish a real copy (with a freshly generated app key/secret) into your
 * app's config/socket.php.
 */
return [

    'apps' => [
        [
            'id'      => env('SOCKET_APP_ID', 'libxa'),
            'key'     => env('SOCKET_APP_KEY', 'libxa-key'),
            'secret'  => env('SOCKET_APP_SECRET', 'libxa-secret'),
            'options' => [
                'host' => env('SOCKET_HOST', '0.0.0.0'),
                'port' => (int) env('SOCKET_PORT', 8080),
            ],
        ],
    ],

];
