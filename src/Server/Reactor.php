<?php

declare(strict_types=1);

namespace LibxaSocket\Server;

use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response as PsrResponse;
use LibxaSocket\ApplicationManager;
use LibxaSocket\Logging\CliLogger;
use LibxaSocket\Protocol\ChannelManager;
use LibxaSocket\Protocol\Connection;
use LibxaSocket\Protocol\MessageHandler;
use Ratchet\RFC6455\Handshake\ServerNegotiator;
use Ratchet\RFC6455\Messaging\CloseFrameChecker;
use Ratchet\RFC6455\Messaging\Frame;
use Ratchet\RFC6455\Messaging\MessageBuffer;
use React\EventLoop\Loop;
use React\Socket\ConnectionInterface;
use React\Socket\SocketServer;

/**
 * The server.
 *
 * One TCP listener speaking two things, decided by the request path, which is
 * how Reverb and Pusher itself are arranged:
 *
 *   /app/{key}   a WebSocket connection from a browser
 *   /apps/{id}/* the signed HTTP API your application publishes through
 *
 * Sharing a port is not a shortcut. The alternative is two listeners, two
 * firewall rules and two URLs in every deployment, to separate traffic that is
 * already separated by path.
 *
 * ReactPHP rather than Workerman, matching what Laravel Reverb is built on:
 * react/socket for the loop and the listener, ratchet/rfc6455 for the
 * handshake and framing.
 */
class Reactor
{
    private ChannelManager $channels;

    private MessageHandler $handler;

    private HttpApi $api;

    /** @var array<string, MessageBuffer> */
    private array $buffers = [];

    private ?SocketServer $socket = null;

    public function __construct(
        private readonly ApplicationManager $applications,
        private readonly CliLogger $logger,
        private readonly bool $allowClientEvents = true,
    ) {
        $this->channels = new ChannelManager();

        $this->handler = new MessageHandler(
            $this->channels,
            new \LibxaSocket\Auth\ChannelAuthenticator(),
            $this->allowClientEvents,
        );

        $this->api = new HttpApi($this->applications, $this->channels, $this->logger);
    }

    public function channels(): ChannelManager
    {
        return $this->channels;
    }

    public function start(string $host, int $port): void
    {
        $this->socket = new SocketServer("{$host}:{$port}");

        $this->socket->on('connection', function (ConnectionInterface $socket): void {
            $this->onOpen($socket);
        });

        $this->socket->on('error', function (\Throwable $e): void {
            $this->logger->error('Listener error: ' . $e->getMessage());
        });

        $this->startHeartbeat();

        $this->logger->info("Listening on {$host}:{$port}");

        Loop::run();
    }

    public function stop(): void
    {
        $this->socket?->close();

        Loop::stop();
    }

    /**
     * Stop when `socket:restart` asks, so a supervisor starts us on new code.
     *
     * The value at boot is the baseline: comparing against "has a signal ever
     * been written" would stop the server every time it started.
     */
    public function watchForRestart(RestartSignal $signal): void
    {
        $bootedWith = $signal->read();

        Loop::addPeriodicTimer(2.0, function () use ($signal, $bootedWith): void {
            if ($signal->read() !== $bootedWith) {
                $this->logger->info('Restart signalled. Stopping.');

                $this->stop();
            }
        });
    }

    // ── connection lifecycle ─────────────────────────────────────────────

    private function onOpen(ConnectionInterface $socket): void
    {
        // The first chunk is the HTTP request; what it asks for decides
        // whether this becomes a WebSocket or is answered and closed.
        $socket->once('data', function (string $data) use ($socket): void {
            try {
                $request = Message::parseRequest($data);
            } catch (\Throwable) {
                $this->respond($socket, new PsrResponse(400, [], 'Bad request.'));

                return;
            }

            $path = $request->getUri()->getPath();

            if (str_starts_with($path, '/app/')) {
                $this->upgrade($socket, $request, $path);

                return;
            }

            $this->respond($socket, $this->api->handle($request));
        });

        $socket->on('error', function (\Throwable $e): void {
            $this->logger->debug('Socket error: ' . $e->getMessage());
        });
    }

    private function upgrade(ConnectionInterface $socket, $request, string $path): void
    {
        $key = substr($path, strlen('/app/'));
        $application = $this->applications->findByKey($key);

        if ($application === null) {
            // Answered over HTTP rather than after upgrading, because a client
            // given a WebSocket and then an error cannot tell a wrong key from
            // a server fault, and retries forever.
            $this->respond($socket, new PsrResponse(401, [], 'Unknown application key.'));

            return;
        }

        $negotiator = new ServerNegotiator(
            new \Ratchet\RFC6455\Handshake\RequestVerifier(),
            new \GuzzleHttp\Psr7\HttpFactory(),
        );

        $response = $negotiator->handshake($request);

        if ($response->getStatusCode() !== 101) {
            $this->respond($socket, $response);

            return;
        }

        $socket->write(Message::toString($response));

        $connection = new Connection(
            id: Connection::generateId(),
            application: $application,
            socket: $socket,
            writer: function (ConnectionInterface $target, string $payload): void {
                $target->write((new Frame($payload, true, Frame::OP_TEXT))->getContents());
            },
        );

        $this->channels->add($connection);

        $buffer = new MessageBuffer(
            new CloseFrameChecker(),
            function ($message) use ($connection): void {
                $this->handler->handle($connection, (string) $message->getPayload());
            },
            function (Frame $frame) use ($connection, $socket): void {
                match ($frame->getOpcode()) {
                    Frame::OP_PING => $socket->write((new Frame($frame->getPayload(), true, Frame::OP_PONG))->getContents()),
                    Frame::OP_PONG => $connection->touch(),
                    Frame::OP_CLOSE => $socket->close(),
                    default => null,
                };
            },
            // Clients must mask every frame they send; the server must not.
            // Expecting the mask is what makes an unmasked client frame the
            // protocol violation it is, rather than data read as if it were
            // fine.
            expectMask: true,
        );

        $this->buffers[$connection->id] = $buffer;

        $socket->on('data', static function (string $chunk) use ($buffer): void {
            $buffer->onData($chunk);
        });

        $socket->on('close', function () use ($connection): void {
            $this->onClose($connection);
        });

        $this->handler->established($connection);

        $this->logger->debug("Connected {$connection->id} on app [{$application->id}]");
    }

    private function onClose(Connection $connection): void
    {
        // Presence departures are announced from the channels the connection
        // was in, which have to be read before the connection is forgotten.
        foreach ($this->channels->remove($connection) as $left) {
            if ($left['channel']->isPresence() && $left['member'] !== null) {
                $this->handler->announceDeparture($left['channel'], $connection->id, $left['member']);
            }
        }

        unset($this->buffers[$connection->id]);

        $this->logger->debug("Disconnected {$connection->id}");
    }

    // ── liveness ─────────────────────────────────────────────────────────

    /**
     * Ping quiet connections, and hang up on the ones that never answer.
     *
     * Without this, connections that vanished without a FIN — a laptop lid
     * closing, a phone changing network — stay in memory as members of every
     * presence channel they joined, so a roster slowly fills with people who
     * left hours ago.
     */
    private function startHeartbeat(): void
    {
        Loop::addPeriodicTimer(MessageHandler::ACTIVITY_TIMEOUT / 2, function (): void {
            foreach ($this->channels->allConnections() as $connection) {
                $idle = $connection->idleFor();

                if ($connection->isAwaitingPong() && $idle > MessageHandler::ACTIVITY_TIMEOUT * 2) {
                    $this->logger->debug("Closing {$connection->id}: no pong.");
                    $connection->close();

                    continue;
                }

                if ($idle > MessageHandler::ACTIVITY_TIMEOUT && ! $connection->isAwaitingPong()) {
                    $connection->ping();
                }
            }
        });
    }

    private function respond(ConnectionInterface $socket, PsrResponse $response): void
    {
        $socket->write(Message::toString($response));
        $socket->end();
    }
}
