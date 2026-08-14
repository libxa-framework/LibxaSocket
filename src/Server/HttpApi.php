<?php

declare(strict_types=1);

namespace LibxaSocket\Server;

use GuzzleHttp\Psr7\Response as PsrResponse;
use LibxaSocket\ApplicationManager;
use LibxaSocket\Logging\CliLogger;
use LibxaSocket\Protocol\Channel;
use LibxaSocket\Protocol\ChannelManager;
use Psr\Http\Message\RequestInterface;

/**
 * The publishing API.
 *
 * How an application gets an event to connected browsers: it POSTs here, and
 * the server fans it out. The routes are Pusher's, so `pusher/pusher-php-server`
 * and every other official client works against this server pointed at it.
 *
 *   POST /apps/{id}/events                     publish
 *   GET  /apps/{id}/channels                   what is occupied
 *   GET  /apps/{id}/channels/{channel}         one channel
 *   GET  /apps/{id}/channels/{channel}/users   presence roster
 *   GET  /up                                   health, unsigned
 *
 * Every route but the health check is signed. An unauthenticated publish
 * endpoint is a way for anyone who can reach the port to send any message to
 * any of your users.
 */
class HttpApi
{
    /** Refuse bodies larger than this, in bytes. */
    private const MAX_BODY = 1_048_576;

    private RequestSignature $signature;

    public function __construct(
        private readonly ApplicationManager $applications,
        private readonly ChannelManager $channels,
        private readonly CliLogger $logger,
    ) {
        $this->signature = new RequestSignature();
    }

    public function handle(RequestInterface $request): PsrResponse
    {
        $path = $request->getUri()->getPath();

        if ($path === '/up' || $path === '/health') {
            return $this->json(200, [
                'status' => 'ok',
                'connections' => $this->channels->connectionCount(),
            ]);
        }

        if (! preg_match('#^/apps/([^/]+)/(.*)$#', $path, $matches)) {
            return $this->json(404, ['error' => 'Not found.']);
        }

        [$appId, $rest] = [rawurldecode($matches[1]), $matches[2]];

        $app = $this->applications->findById($appId);

        if ($app === null) {
            // Same answer as a bad signature below, so this cannot be used to
            // discover which application ids exist.
            return $this->json(401, ['error' => 'Unauthorized.']);
        }

        $body = (string) $request->getBody();

        if (strlen($body) > self::MAX_BODY) {
            return $this->json(413, ['error' => 'Payload too large.']);
        }

        if (($why = $this->signature->verify($request, $app, $body)) !== null) {
            $this->logger->debug("Rejected API request: {$why}");

            return $this->json(401, ['error' => 'Unauthorized.']);
        }

        return match (true) {
            $rest === 'events' && $request->getMethod() === 'POST' => $this->publish($appId, $body),
            $rest === 'channels' => $this->channelIndex($appId),
            (bool) preg_match('#^channels/([^/]+)/users$#', $rest, $m) => $this->users($appId, rawurldecode($m[1])),
            (bool) preg_match('#^channels/([^/]+)$#', $rest, $m) => $this->channel($appId, rawurldecode($m[1])),
            default => $this->json(404, ['error' => 'Not found.']),
        };
    }

    // ── routes ───────────────────────────────────────────────────────────

    /**
     * Publish an event to one or more channels.
     *
     * Body: {"name": "...", "channel"|"channels": ..., "data": ..., "socket_id": "..."}
     */
    private function publish(string $appId, string $body): PsrResponse
    {
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return $this->json(400, ['error' => 'Body must be a JSON object.']);
        }

        $event = $payload['name'] ?? $payload['event'] ?? null;

        if (! is_string($event) || $event === '') {
            return $this->json(400, ['error' => 'An event name is required.']);
        }

        // Reserved, so an application cannot publish something a client would
        // mistake for the server's own protocol messages — a forged
        // pusher_internal:member_added would corrupt every roster listening.
        if (str_starts_with($event, 'pusher:') || str_starts_with($event, 'pusher_internal:')) {
            return $this->json(400, ['error' => 'That event name is reserved.']);
        }

        $names = $this->channelNames($payload);

        if ($names === []) {
            return $this->json(400, ['error' => 'At least one channel is required.']);
        }

        $except = is_string($payload['socket_id'] ?? null) ? $payload['socket_id'] : null;

        // Passed through as-is. Pusher's data field is a string on the wire,
        // and re-encoding a value the caller already encoded would deliver
        // double-encoded JSON to every subscriber.
        $data = $payload['data'] ?? [];

        $delivered = 0;

        foreach ($names as $name) {
            $channel = $this->channels->existing($appId, $name);

            if ($channel === null) {
                // Nobody is listening. Not an error: publishing to an empty
                // channel is normal, and reporting it as a failure would make
                // every caller handle a case they cannot act on.
                continue;
            }

            $delivered += $channel->broadcast($event, $data, $except);
        }

        $this->logger->debug(sprintf('Published [%s] to %s (%d client(s))', $event, implode(', ', $names), $delivered));

        return $this->json(200, ['ok' => true, 'delivered' => $delivered]);
    }

    private function channelIndex(string $appId): PsrResponse
    {
        $channels = [];

        foreach ($this->channels->all($appId) as $name => $channel) {
            $channels[$name] = $channel->isPresence()
                ? ['user_count' => $channel->presencePayload()['presence']['count']]
                : [];
        }

        return $this->json(200, ['channels' => (object) $channels]);
    }

    private function channel(string $appId, string $name): PsrResponse
    {
        $channel = $this->channels->existing($appId, $name);

        if ($channel === null) {
            return $this->json(200, ['occupied' => false, 'subscription_count' => 0]);
        }

        $body = [
            'occupied' => true,
            'subscription_count' => $channel->count(),
        ];

        if ($channel->isPresence()) {
            $body['user_count'] = $channel->presencePayload()['presence']['count'];
        }

        return $this->json(200, $body);
    }

    private function users(string $appId, string $name): PsrResponse
    {
        $channel = $this->channels->existing($appId, $name);

        if ($channel === null || ! $channel->isPresence()) {
            return $this->json(400, ['error' => 'Not a presence channel.']);
        }

        $users = [];

        foreach (array_keys($channel->presencePayload()['presence']['hash']) as $id) {
            $users[] = ['id' => (string) $id];
        }

        return $this->json(200, ['users' => $users]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * The channels a publish is addressed to.
     *
     * @return list<string>
     */
    private function channelNames(array $payload): array
    {
        $names = [];

        if (is_string($payload['channel'] ?? null)) {
            $names[] = $payload['channel'];
        }

        foreach ((array) ($payload['channels'] ?? []) as $name) {
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    private function json(int $status, array $body): PsrResponse
    {
        return new PsrResponse(
            $status,
            ['Content-Type' => 'application/json'],
            (string) json_encode($body, JSON_UNESCAPED_SLASHES),
        );
    }
}
