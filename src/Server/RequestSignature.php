<?php

declare(strict_types=1);

namespace LibxaSocket\Server;

use LibxaSocket\Application;
use Psr\Http\Message\RequestInterface;

/**
 * Verifies the signature on an HTTP API request.
 *
 * This is Pusher's REST authentication, unchanged, so the official server
 * libraries can publish to this server without knowing it is not Pusher. A
 * request carries, as query parameters:
 *
 *   auth_key        which application is calling
 *   auth_timestamp  when, in seconds
 *   auth_version    always 1.0
 *   body_md5        md5 of the request body, when there is one
 *   auth_signature  HMAC-SHA256 of "{METHOD}\n{path}\n{sorted other params}"
 *
 * The body hash is inside the signed string, so a signature cannot be lifted
 * from one publish and reused to send different content to the same channel.
 */
class RequestSignature
{
    /** How far out of date a request may be, in seconds. */
    public const MAX_AGE = 600;

    /**
     * @return string|null null when valid, otherwise why not
     */
    public function verify(RequestInterface $request, Application $app, string $body): ?string
    {
        parse_str($request->getUri()->getQuery(), $params);

        $signature = (string) ($params['auth_signature'] ?? '');

        if ($signature === '') {
            return 'Missing auth_signature.';
        }

        if (! hash_equals($app->key, (string) ($params['auth_key'] ?? ''))) {
            return 'Unknown auth_key.';
        }

        $timestamp = (int) ($params['auth_timestamp'] ?? 0);

        // A replay window. Without it a captured request is valid forever,
        // and the body hash only stops it being *modified*, not repeated.
        if ($timestamp <= 0 || abs(time() - $timestamp) > self::MAX_AGE) {
            return 'Stale or missing auth_timestamp.';
        }

        if ($body !== '') {
            $expectedHash = md5($body);

            if (! hash_equals($expectedHash, (string) ($params['body_md5'] ?? ''))) {
                return 'body_md5 does not match the body.';
            }
        }

        $expected = $this->sign(
            $request->getMethod(),
            $request->getUri()->getPath(),
            $params,
            $app->secret,
        );

        return hash_equals($expected, $signature) ? null : 'Signature mismatch.';
    }

    /**
     * The signature for a request.
     *
     * @param array<string, mixed> $params every query parameter; auth_signature
     *        itself is excluded, since it cannot sign itself
     */
    public function sign(string $method, string $path, array $params, string $secret): string
    {
        unset($params['auth_signature']);

        // Sorted by key, lowercased: both ends have to agree on the order, and
        // "the order they happened to be in" is not something two languages'
        // query-string builders will agree on.
        $params = array_change_key_case($params, CASE_LOWER);
        ksort($params);

        $canonical = strtoupper($method) . "\n" . $path . "\n" . http_build_query($params);

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Build the query parameters a client should send.
     *
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    public function queryFor(Application $app, string $method, string $path, string $body = '', array $extra = []): array
    {
        $params = array_merge([
            'auth_key' => $app->key,
            'auth_timestamp' => (string) time(),
            'auth_version' => '1.0',
        ], $extra);

        if ($body !== '') {
            $params['body_md5'] = md5($body);
        }

        $params['auth_signature'] = $this->sign($method, $path, $params, $app->secret);

        return $params;
    }
}
