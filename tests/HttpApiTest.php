<?php

declare(strict_types=1);

namespace LibxaSocket\Tests;

use GuzzleHttp\Psr7\Request;
use LibxaSocket\Application;
use LibxaSocket\ApplicationManager;
use LibxaSocket\Logging\CliLogger;
use LibxaSocket\Server\HttpApi;
use LibxaSocket\Server\RequestSignature;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The publishing API.
 *
 * This is the endpoint an application posts to, and therefore the endpoint
 * anyone who can reach the port would post to if it were not signed. Most of
 * what is here is about refusing.
 */
final class HttpApiTest extends ProtocolTestCase
{
    private HttpApi $api;

    private RequestSignature $signature;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signature = new RequestSignature();

        $this->api = new HttpApi(
            new ApplicationManager([
                ['id' => 'demo', 'key' => 'demo-key', 'secret' => 'demo-secret'],
            ]),
            $this->channels,
            new CliLogger(new NullOutput()),
        );
    }

    /** A correctly signed request. */
    private function signed(string $method, string $path, string $body = '', ?Application $as = null): Request
    {
        $app = $as ?? $this->app;

        $query = http_build_query($this->signature->queryFor($app, $method, $path, $body));

        return new Request($method, $path . '?' . $query, ['Content-Type' => 'application/json'], $body);
    }

    private function decode($response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }

    private function publishBody(string $event, array|string $channels, mixed $data = [], ?string $socketId = null): string
    {
        $body = ['name' => $event, 'data' => is_string($data) ? $data : json_encode($data)];

        if (is_array($channels)) {
            $body['channels'] = $channels;
        } else {
            $body['channel'] = $channels;
        }

        if ($socketId !== null) {
            $body['socket_id'] = $socketId;
        }

        return (string) json_encode($body);
    }

    // ── health ───────────────────────────────────────────────────────────

    public function test_the_health_endpoint_needs_no_signature(): void
    {
        // So a load balancer can use it without holding the secret.
        $response = $this->api->handle(new Request('GET', '/up'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $this->decode($response)['status']);
    }

    // ── authentication ───────────────────────────────────────────────────

    public function test_an_unsigned_publish_is_refused(): void
    {
        $response = $this->api->handle(
            new Request('POST', '/apps/demo/events', [], $this->publishBody('X', 'orders')),
        );

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_publish_signed_with_the_wrong_secret_is_refused(): void
    {
        $impostor = new Application('demo', 'demo-key', 'not-the-secret');
        $body = $this->publishBody('X', 'orders');

        $response = $this->api->handle($this->signed('POST', '/apps/demo/events', $body, $impostor));

        self::assertSame(401, $response->getStatusCode());
    }

    public function test_a_signed_request_whose_body_was_changed_is_refused(): void
    {
        // The body hash is inside the signed string, so a captured publish
        // cannot be edited and replayed against the same channel.
        $body = $this->publishBody('X', 'orders');
        $request = $this->signed('POST', '/apps/demo/events', $body);

        $tampered = $request->withBody(\GuzzleHttp\Psr7\Utils::streamFor(
            $this->publishBody('X', 'orders', ['tampered' => true]),
        ));

        self::assertSame(401, $this->api->handle($tampered)->getStatusCode());
    }

    public function test_a_stale_request_is_refused(): void
    {
        // Without a window, a captured request is valid forever.
        $path = '/apps/demo/events';
        $body = $this->publishBody('X', 'orders');

        $params = [
            'auth_key' => 'demo-key',
            'auth_timestamp' => (string) (time() - RequestSignature::MAX_AGE - 60),
            'auth_version' => '1.0',
            'body_md5' => md5($body),
        ];
        $params['auth_signature'] = $this->signature->sign('POST', $path, $params, 'demo-secret');

        $request = new Request('POST', $path . '?' . http_build_query($params), [], $body);

        self::assertSame(401, $this->api->handle($request)->getStatusCode());
    }

    public function test_an_unknown_application_answers_the_same_as_a_bad_signature(): void
    {
        // Otherwise the difference enumerates which application ids exist.
        $unknown = $this->api->handle($this->signed('GET', '/apps/nope/channels'));
        $badSignature = $this->api->handle(new Request('GET', '/apps/demo/channels'));

        self::assertSame(401, $unknown->getStatusCode());
        self::assertSame($badSignature->getStatusCode(), $unknown->getStatusCode());
        self::assertSame($this->decode($badSignature), $this->decode($unknown));
    }

    // ── publishing ───────────────────────────────────────────────────────

    public function test_a_signed_publish_reaches_subscribers(): void
    {
        $connection = $this->connect();
        $this->subscribe($connection, 'orders');
        $this->drain($connection);

        $body = $this->publishBody('OrderShipped', 'orders', ['id' => 7]);
        $response = $this->api->handle($this->signed('POST', '/apps/demo/events', $body));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->decode($response)['delivered']);

        $message = $this->lastMessage($connection);

        self::assertSame('OrderShipped', $message['event']);
        self::assertSame('orders', $message['channel']);
        self::assertSame(['id' => 7], $message['data']);
    }

    public function test_socket_id_excludes_the_originator(): void
    {
        // The client that caused the change already rendered it; echoing it
        // back makes the change appear to happen twice.
        $alice = $this->connect();
        $bob = $this->connect();

        $this->subscribe($alice, 'orders');
        $this->subscribe($bob, 'orders');
        $this->drain($alice);
        $this->drain($bob);

        $body = $this->publishBody('OrderShipped', 'orders', ['id' => 7], $alice->id);
        $this->api->handle($this->signed('POST', '/apps/demo/events', $body));

        self::assertSame([], $this->sent($alice));
        self::assertSame('OrderShipped', $this->lastEvent($bob));
    }

    public function test_publishing_to_several_channels(): void
    {
        $a = $this->connect();
        $b = $this->connect();

        $this->subscribe($a, 'orders');
        $this->subscribe($b, 'invoices');
        $this->drain($a);
        $this->drain($b);

        $body = $this->publishBody('Updated', ['orders', 'invoices'], ['at' => 'now']);
        $response = $this->api->handle($this->signed('POST', '/apps/demo/events', $body));

        self::assertSame(2, $this->decode($response)['delivered']);
        self::assertSame('Updated', $this->lastEvent($a));
        self::assertSame('Updated', $this->lastEvent($b));
    }

    public function test_publishing_to_an_empty_channel_is_not_an_error(): void
    {
        // Normal, and not something a caller could act on.
        $body = $this->publishBody('Nobody', 'listening');
        $response = $this->api->handle($this->signed('POST', '/apps/demo/events', $body));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->decode($response)['delivered']);
    }

    public function test_protocol_event_names_are_reserved(): void
    {
        // A forged pusher_internal:member_added would corrupt every roster
        // listening to that channel.
        foreach (['pusher:connection_established', 'pusher_internal:member_added'] as $event) {
            $body = $this->publishBody($event, 'orders');

            self::assertSame(
                400,
                $this->api->handle($this->signed('POST', '/apps/demo/events', $body))->getStatusCode(),
                $event,
            );
        }
    }

    public function test_a_publish_without_a_channel_is_rejected(): void
    {
        $body = (string) json_encode(['name' => 'X', 'data' => '{}']);

        self::assertSame(400, $this->api->handle($this->signed('POST', '/apps/demo/events', $body))->getStatusCode());
    }

    public function test_a_publish_without_a_name_is_rejected(): void
    {
        $body = (string) json_encode(['channel' => 'orders', 'data' => '{}']);

        self::assertSame(400, $this->api->handle($this->signed('POST', '/apps/demo/events', $body))->getStatusCode());
    }

    // ── introspection ────────────────────────────────────────────────────

    public function test_the_channel_index_lists_occupied_channels(): void
    {
        $connection = $this->connect();
        $this->subscribe($connection, 'orders');

        $response = $this->api->handle($this->signed('GET', '/apps/demo/channels'));

        self::assertArrayHasKey('orders', (array) $this->decode($response)['channels']);
    }

    public function test_an_unoccupied_channel_reports_itself_as_such(): void
    {
        $response = $this->api->handle($this->signed('GET', '/apps/demo/channels/nobody'));

        self::assertFalse($this->decode($response)['occupied']);
        self::assertSame(0, $this->decode($response)['subscription_count']);
    }

    public function test_a_presence_channel_reports_its_user_count(): void
    {
        $connection = $this->connect();
        $this->joinPresence($connection, 'presence-room', '1');

        $response = $this->api->handle($this->signed('GET', '/apps/demo/channels/presence-room'));

        self::assertTrue($this->decode($response)['occupied']);
        self::assertSame(1, $this->decode($response)['user_count']);
    }

    public function test_the_user_list_is_presence_only(): void
    {
        $connection = $this->connect();
        $this->subscribe($connection, 'orders');

        self::assertSame(
            400,
            $this->api->handle($this->signed('GET', '/apps/demo/channels/orders/users'))->getStatusCode(),
        );
    }

    public function test_the_user_list_returns_members(): void
    {
        $connection = $this->connect();
        $this->joinPresence($connection, 'presence-room', '42');

        $response = $this->api->handle($this->signed('GET', '/apps/demo/channels/presence-room/users'));

        self::assertSame([['id' => '42']], $this->decode($response)['users']);
    }
}
