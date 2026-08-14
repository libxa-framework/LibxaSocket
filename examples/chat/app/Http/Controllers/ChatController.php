<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\MessagePosted;
use Libxa\Http\Request;
use Libxa\Http\Response;

/**
 * A room you can talk in.
 *
 * The page renders once over HTTP and then receives everything else over the
 * socket. Posting a message does not return the message: it broadcasts, and
 * the sender's own page draws it from the same event everyone else gets, so
 * there is exactly one path a message can arrive by and nothing to keep in
 * sync.
 */
final class ChatController
{
    public function show(string $room = 'lobby'): Response
    {
        $app = config('socket.apps.0', []);

        return view('chat', [
            'room' => $room,
            // The key is public — it identifies which application a browser is
            // connecting to. The secret stays on the server.
            'socketKey' => $app['key'] ?? '',
            'socketHost' => config('socket.client.host', '127.0.0.1'),
            'socketPort' => config('socket.server.port', 8080),
            'me' => $this->currentUser(),
        ]);
    }

    public function post(Request $request, string $room = 'lobby'): Response
    {
        $body = trim((string) $request->input('body', ''));

        if ($body === '') {
            return response()->json(['message' => 'Say something.'])->withStatus(422);
        }

        if (mb_strlen($body) > 500) {
            return response()->json(['message' => 'Too long.'])->withStatus(422);
        }

        $event = new MessagePosted(
            room: $room,
            author: $this->currentUser()['name'],
            body: $body,
            postedAt: date('H:i:s'),
        );

        broadcast($event);

        // 202, not 200 with the message: the client will receive it over the
        // socket like everybody else. Returning it here too would render it
        // twice, which is the classic realtime bug.
        return response()->json(['accepted' => true])->withStatus(202);
    }

    /**
     * @return array{id: string, name: string}
     */
    private function currentUser(): array
    {
        $user = auth()?->user();

        if ($user !== null) {
            return ['id' => (string) $user->id, 'name' => (string) ($user->name ?? 'Someone')];
        }

        // The demo runs without accounts: a stable per-session identity is
        // enough to show presence working, and nothing here is private.
        $session = session();

        if ($session !== null && $session->get('demo_user') === null) {
            $session->put('demo_user', [
                'id' => (string) random_int(1000, 9999),
                'name' => 'Guest ' . random_int(100, 999),
            ]);
        }

        return $session?->get('demo_user') ?? ['id' => '0', 'name' => 'Guest'];
    }
}
