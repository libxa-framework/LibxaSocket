# LibxaSocket

A WebSocket server for [LibxaFrame](https://github.com/libxa-framework/libxa),
speaking the Pusher protocol.

That last part is the point. Rather than inventing a wire format and shipping a
client to match, this implements the protocol Pusher defined and Laravel Reverb
implements — so **Laravel Echo, `pusher-js`, and every other Pusher client work
against it unchanged**, and so do the server libraries that publish to it.

Built on ReactPHP, the same foundation Reverb uses: `react/socket` for the
event loop and listener, `ratchet/rfc6455` for the handshake and framing.

```bash
composer require libxa/socket
php libxa package:discover
php libxa socket:install
php libxa socket:start
```

## What it does

- **Public, private and presence channels.** The name prefix is the rule —
  `private-` and `presence-` require a signature your application vouches for.
- **A presence roster** that counts people rather than connections: one user
  with two tabs is one member, and closing one tab is not leaving.
- **A signed HTTP API** for publishing, at Pusher's own routes, so
  `pusher/pusher-php-server` can talk to it.
- **Client events** (`client-*`) relayed browser-to-browser on private and
  presence channels, for typing indicators and cursors.
- **`broadcast(new SomethingHappened)`** from your application, through a
  broadcast driver.

## Setting up

`socket:install` publishes `config/socket.php`, generates a key and secret into
your `.env`, and scaffolds `routes/channels.php`.

Then set the broadcast driver:

```dotenv
BROADCAST_DRIVER=socket
```

### Who may listen to what

`routes/channels.php` decides. A private or presence channel with no rule here
is **refused** — the alternative allows what nobody has thought about, which
makes every channel public until somebody remembers it exists.

```php
/** @var \LibxaSocket\Channels\ChannelGate $channel */

// Only the owner may watch their order.
$channel->register('orders.{orderId}', fn ($user, string $orderId): bool =>
    Order::find($orderId)?->user_id === $user->id);

// Presence: return the profile the rest of the room should see.
$channel->register('room.{roomId}', fn ($user, string $roomId): array => [
    'user_id' => (string) $user->id,
    'user_info' => ['name' => $user->name],
]);
```

Register the rule once, without the prefix: `room.{roomId}` covers both
`private-room.1` and `presence-room.1`.

Whatever a presence callback returns is visible to **everyone else in the
channel**, so it should carry a display name and nothing more.

If your realtime identity is not your login — an anonymous support chat keyed
on a session, say — replace the resolver:

```php
$channel->resolveUserUsing(fn () => session()?->get('visitor'));
```

## Broadcasting

```php
final class OrderShipped implements ShouldBroadcast
{
    public function __construct(public readonly Order $order) {}

    public function broadcastOn(): array
    {
        return ['private-orders.' . $this->order->id];
    }

    public function broadcastWith(): array
    {
        return ['status' => $this->order->status];
    }

    public function broadcastAs(): string
    {
        return 'OrderShipped';
    }
}
```

```php
broadcast(new OrderShipped($order));
```

Delivery is best-effort on purpose. A socket server that is down must not take
an HTTP request down with it: the order was placed, and the live update not
arriving is worth logging rather than a 500. Anything that genuinely cannot
lose an event needs a queue.

## Connecting a browser

With Laravel Echo:

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_SOCKET_APP_KEY,
    wsHost: window.location.hostname,
    wsPort: 8080,
    forceTLS: false,
    enabledTransports: ['ws'],
});

Echo.join(`room.${roomId}`)
    .here(users => console.log(users))
    .joining(user => console.log(user.name, 'joined'))
    .leaving(user => console.log(user.name, 'left'))
    .listen('MessagePosted', e => console.log(e.body));
```

Nothing here is LibxaSocket-specific. That is the point of implementing the
protocol rather than inventing one.

`examples/chat` has a working room — presence, live messages and typing
indicators — written against the raw protocol rather than Echo, so every
message the wire format involves is visible in one file.

## Running it

```bash
php libxa socket:start                 # foreground, Ctrl+C to stop
php libxa socket:start --port=8090     # somewhere else
php libxa socket:start --debug         # log every connection and message
php libxa socket:restart               # ask a running server to stop
```

The server holds every connection in memory and loads your code once at boot,
so deploying does not reach it: until it restarts it keeps running the code it
started with. `socket:restart` writes a signal the running server watches; it
stops cleanly and whatever supervises it — systemd, supervisord, Docker —
starts it again. On its own it stops the server and does not start it.

### In front of a browser on HTTPS

This server speaks `ws://`, not `wss://`. A page served over HTTPS will refuse
a `ws://` connection outright, so in production put it behind a reverse proxy
that terminates TLS:

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_read_timeout 3600s;
}
```

`proxy_read_timeout` matters. The default is 60 seconds, and a WebSocket that
is merely quiet looks exactly like one that has stalled.

## The HTTP API

Pusher's routes, signed with Pusher's scheme:

```text
POST /apps/{id}/events
GET  /apps/{id}/channels
GET  /apps/{id}/channels/{channel}
GET  /apps/{id}/channels/{channel}/users
GET  /up                                  health, unsigned
```

Everything but `/up` requires a signature. An unauthenticated publish endpoint
is a way for anyone who can reach the port to send any message to any of your
users.

## Security notes

- **The key is public, the secret is not.** The key ships in your JavaScript
  and identifies which application a browser is connecting to. The secret signs
  channel authorizations and the publishing API; anyone holding it can send any
  message to any of your users.
- **Signatures cover the socket id**, so one minted for a connection cannot be
  replayed by another.
- **Presence `channel_data` is verified byte-for-byte as sent.** Tampering with
  it to join as somebody else fails the signature.
- **`pusher:` and `pusher_internal:` event names are reserved** on the
  publishing API. A forged `member_added` would corrupt every roster listening.
- **Client events are private and presence only.** A public channel anyone can
  join is one anyone could publish to.

## Scaling

One process, holding every connection and channel in memory. That is a real
limit: two processes do not share channels, so a client connected to one will
not receive an event published through the other.

For a single server this is usually fine — ReactPHP handles thousands of
connections in one process, and the work per message is small. Beyond that you
need a shared backplane, which this does not have yet. Reverb solves it with
Redis pub/sub; the same approach fits here and is the obvious next thing to
build.

## Requirements

PHP 8.3+, and `libxa/framework` ^0.11.2.

## License

MIT.
