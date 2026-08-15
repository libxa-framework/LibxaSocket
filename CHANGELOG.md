# Changelog

All notable changes to LibxaSocket are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-08-13

The server now speaks the Pusher protocol, on ReactPHP.

> **This replaces the previous server rather than extending it.** The old one
> had a wire format of its own and a channel-class API built on PHP attributes;
> both are gone. Nothing that spoke to the old server speaks to this one — but
> `pusher-js`, `pusher/pusher-php-server` and every other Pusher client do,
> without a shim.

### Why the rewrite

The old implementation was built on Workerman with a bespoke protocol, and its
own notes recorded the consequence: *"I did not reimplement the full Pusher
wire protocol, which would break the standard clients."* That meant
every client had to be written against this server specifically, and every
existing realtime tool was unusable with it.

The reference PHP implementation of this protocol is built on **ReactPHP**, not
Workerman — `react/socket` for the loop and listener, `ratchet/rfc6455` for the
handshake and framing. This now uses the same stack, and implements the same
protocol.

### Added

- **The Pusher wire protocol.** `pusher:connection_established`,
  `pusher:subscribe` / `unsubscribe`, `pusher:ping` / `pong`, `pusher:error`,
  `pusher_internal:subscription_succeeded`, `member_added`, `member_removed`,
  and `client-*` events. Existing Pusher clients connect to it as if it were
  Pusher.

- **Public, private and presence channels**, distinguished by name prefix.
  The prefix is the whole rule, so a channel cannot be left unprotected by
  forgetting to declare it — naming it `private-` is the declaration.

- **A presence roster that counts people, not connections.** One user with two
  tabs is one member; closing one tab is not leaving. Getting this wrong makes
  "3 people online" wrong in the one way users notice.

- **A signed HTTP API** at Pusher's own routes — `POST /apps/{id}/events`, the
  channel introspection endpoints, and an unsigned `/up` for health checks.
  The signature covers the request body, so a captured publish cannot be edited
  and replayed, and carries a timestamp, so it cannot be replayed at all after
  ten minutes.

- **`BROADCAST_DRIVER=socket`**, so `broadcast(new SomethingHappened)` reaches
  connected browsers. Publishing is best-effort: a socket server that is down
  must not take an HTTP request down with it.

- **`/broadcasting/auth`**, the endpoint Echo calls before joining a private or
  presence channel, and a `ChannelGate` for the rules behind it. A channel with
  no rule is **refused** — the alternative allows what nobody has thought
  about, which makes every private channel public until somebody remembers it
  exists.

- **`resolveUserUsing()`**, for applications whose realtime identity is not
  their login: an anonymous support chat keyed on a session, a device rather
  than a person. Without it those applications cannot use presence channels at
  all, since every subscription would be refused for want of a user.

- **A heartbeat.** Quiet connections are pinged and unresponsive ones dropped.
  Without it, connections that vanished without a FIN — a laptop lid closing, a
  phone changing network — stay in memory as members of every presence channel
  they joined, so a roster slowly fills with people who left hours ago.

- **`socket:restart`.** The server holds every connection in memory and loads
  your code once at boot, so deploying does not reach it. This writes a signal
  the running server watches; it stops cleanly and whatever supervises it starts
  it again.

- **`examples/chat`** — a working room with presence, live messages and typing
  indicators, written against the raw protocol rather than Echo so every
  message the wire format involves is visible in one file.

### Security

- **Signatures cover the socket id**, so one minted for a connection cannot be
  replayed by another. A token leaked from one browser is useless from another.
- **Presence `channel_data` is verified byte-for-byte as sent**, not re-encoded
  from the decoded value. Re-encoding produces different JSON — different key
  order, different escaping — so correct signatures would fail and, worse, the
  natural fix is to stop checking. Tampering with it to join as somebody else
  fails the signature.
- **`pusher:` and `pusher_internal:` names are reserved** on the publishing
  API. A forged `member_added` would corrupt every roster listening.
- **Client events are private and presence only.** A public channel anyone can
  join is one anyone could publish to, which is a spam relay.
- **The publishing API is signed.** Unauthenticated, it is a way for anyone who
  can reach the port to send any message to any of your users.

### Removed

- The Workerman transport, and `workerman/workerman` as a dependency.
- The bespoke protocol: `Server`, `WsConnection`, `WsChannel`, `WsRouter`,
  `WsMessage`, `WsMessageDto`, `WsBroadcast`, `PresenceRegistry` and the
  `#[WsRoute]` / `#[OnEvent]` attribute API.
- The LiveLib component mounting in the old server, which referenced
  `Libxa\LiveLib\ComponentRegistry` — a class that does not exist in the
  framework, so those code paths could only ever have raised an error.

### Known limits

One process, holding every connection and channel in memory. Two processes do
not share channels, so a client connected to one will not receive an event
published through the other. For a single server this is usually fine; beyond
that a shared backplane is needed, which this does not have yet. The usual
answer is Redis pub/sub and it fits here.

### Requires

PHP 8.3+ and `libxa/framework` ^0.11.2, which contains the `broadcast()` fix
and the `BroadcastManager::extend()` hook this package registers through.
