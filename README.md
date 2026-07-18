# LibxaSocket

A standalone WebSocket server for **LibxaFrame**, built on Workerman —
extracted out of the core framework into its own package, in the same
spirit as Laravel Reverb (a dedicated realtime server your app talks to,
rather than WS code baked into the framework core).

## Why a separate package?

Not every LibxaFrame app needs a WebSocket server. Keeping it as an
optional `libxa/socket` package means:

- The core framework has one less runtime dependency (Workerman) for apps
  that don't need realtime features.
- `route:cache` / core routing isn't entangled with WS-specific routing.
- You can version and update the socket server independently of the
  framework.

## Install

```bash
composer require libxa/socket
php Libxa package:discover
php Libxa socket:install
```

`socket:install` will:

- Publish `config/socket.php` with a freshly generated app id/key/secret.
- Add `SOCKET_HOST` / `SOCKET_PORT` / `SOCKET_WORKERS` to your `.env`.
- Scaffold an example channel at `app/WebSockets/RandomChannel.php`.
- Scaffold a demo page at `/socket-test` (controller-based, so it's safe
  to run alongside `route:cache`).

## Running the server

```bash
php Libxa socket:start                # foreground, Ctrl+C to stop
php Libxa socket:start --port=8090    # custom port
php Libxa socket:start --debug        # verbose connection/message logging
php Libxa socket:restart              # signal running workers to restart
```

On Windows, Workerman only supports a single process — `--workers` is
ignored there (with a note), rather than silently pretending it worked.

If the server fails to bind (`Unable to connect to tcp://...` /
`WSAEACCES` on Windows), `socket:start` now prints actionable next steps
instead of a raw stack trace — check the note it prints for the most
common Windows cause (Hyper-V/WSL2 port-exclusion ranges).

## Writing a channel

Channels are plain classes with lifecycle hooks, matched to a URI via the
`#[WsRoute]` attribute:

```php
namespace App\WebSockets;

use LibxaSocket\WsChannel;
use LibxaSocket\WsConnection;
use LibxaSocket\Attributes\WsRoute;
use LibxaSocket\Attributes\OnEvent;

#[WsRoute('/ws/chat/{room}')]
class ChatChannel extends WsChannel
{
    public function onOpen(WsConnection $connection): void
    {
        $connection->join($connection->param('room'));
    }

    #[OnEvent('message')]
    public function handleMessage(WsConnection $connection, $message): void
    {
        $connection->broadcastToRoom($connection->param('room'), 'message', [
            'text' => $message->data('text'),
        ]);
    }

    public function onClose(WsConnection $connection): void
    {
        // cleanup handled automatically for room membership
    }
}
```

Channel classes under `app/WebSockets/` are scanned automatically when the
socket server boots.

## Multiple apps

`config/socket.php` supports registering more than one app (each with its
own id/key/secret), the same way Reverb does — useful if you're running
one socket server for several tenants/products:

```php
'apps' => [
    ['id' => 'main',  'key' => env('SOCKET_APP_KEY'),  'secret' => env('SOCKET_APP_SECRET')],
    ['id' => 'admin', 'key' => env('ADMIN_SOCKET_KEY'), 'secret' => env('ADMIN_SOCKET_SECRET')],
],
```

Resolve the registry anywhere via the container:

```php
use LibxaSocket\ApplicationManager;

$apps = app(ApplicationManager::class);
$app  = $apps->findById('main');
```

## Authorizing private/presence channels

`LibxaSocket\Auth\ChannelAuthenticator` gives you a Pusher/Reverb-style
signed-auth primitive for channels your app wants to gate (naming
convention: prefix a channel with `private-` or `presence-`). Typical use
is an HTTP endpoint your frontend calls before subscribing:

```php
use LibxaSocket\Auth\ChannelAuthenticator;
use LibxaSocket\ApplicationManager;

Route::post('/socket/auth', function (Request $request) use ($apps, $auth) {
    $app = $apps->findById('main');

    $signature = $auth->sign(
        $app,
        $request->input('socket_id'),
        $request->input('channel_name'),
    );

    return ['auth' => "{$app->key}:{$signature}"];
});
```

Your channel's `onOpen()`/subscribe handling can then call
`$auth->verify(...)` with the signature the client presents, and reject
the subscription if it doesn't match. `ChannelAuthenticator::isProtectedChannel()`
/ `isPresenceChannel()` are there to check the naming convention.

## Presence channels

`LibxaSocket\Channels\PresenceRegistry` tracks who's currently on a
`presence-*` channel (per-worker, in-memory) so you can tell newly-joining
members who else is there, and announce joins/leaves:

```php
use LibxaSocket\Channels\PresenceRegistry;

$presence = app(PresenceRegistry::class);
$presence->join($channel, $connection->id, ['name' => $user->name]);
$members = $presence->members($channel);
```

For a single-worker deployment this is enough. For horizontal scaling
across multiple socket-server processes, you'd back this with Redis
instead — that's a deliberate scope cut here, not an oversight; happy to
build that out if/when you need to scale past one process.

## Broadcasting from your HTTP app

`LibxaSocket\Broadcasting\WsBroadcast` is the facade your controllers/jobs
use to push events from the normal HTTP process into the socket server:

```php
use LibxaSocket\Broadcasting\WsBroadcast;

WsBroadcast::toRoom('chat.general')->emit('message', ['text' => 'hi']);
```

Internally this goes through the framework's existing pluggable
`Broadcaster` (`Libxa\Broadcasting\BroadcastManager`), the same one used by
`ws()` / `broadcast()` helpers — set `BROADCAST_DRIVER=ws` in `.env` if
you want that to be the default driver.

## What moved from the core framework

If you're upgrading from a version of LibxaFrame that had `ws:serve` /
`ws:install` built in, here's the mapping:

| Old (core) | New (libxa/socket) |
|---|---|
| `php Libxa ws:serve` | `php Libxa socket:start` |
| `php Libxa ws:install` | `php Libxa socket:install` |
| `Libxa\WebSockets\*` | `LibxaSocket\*` |
| `Libxa\Reactive\WsServer` | `LibxaSocket\Server` |
| `WS_HOST` / `WS_PORT` / `WS_WORKERS` | `SOCKET_HOST` / `SOCKET_PORT` / `SOCKET_WORKERS` (old `WS_*` vars still work as a fallback) |

`Libxa\Reactive\ReactiveComponent` and `DiffEngine` (the server-driven UI
diffing primitives used by `@reactive` Blade components) stayed in the
core framework — they're transport-agnostic and don't need Workerman
directly, only a running socket server to actually push their diffs.
