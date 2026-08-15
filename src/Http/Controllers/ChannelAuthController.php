<?php

declare(strict_types=1);

namespace LibxaSocket\Http\Controllers;

use LibxaSocket\ApplicationManager;
use LibxaSocket\Auth\ChannelAuthenticator;
use LibxaSocket\Channels\ChannelGate;
use Libxa\Http\Request;
use Libxa\Http\Response;

/**
 * The endpoint a client calls before joining a private or presence channel.
 *
 * The browser POSTs `{socket_id, channel_name}` here with its session
 * cookie, and expects `{auth: "key:signature"}` back — plus `channel_data` for
 * presence channels. Its job is to answer one question: may *this* user listen
 * to *this* channel?
 *
 * That question is the only thing standing between a private channel and the
 * public. The signature proves the server said yes; it does not decide it.
 * Deciding is what the channel callbacks registered in routes/channels.php do,
 * and a channel with no callback is refused rather than allowed — an
 * authorization endpoint that defaults to yes is a private channel in name.
 */
class ChannelAuthController
{
    public function __construct(
        private readonly ApplicationManager $applications,
        private readonly ChannelAuthenticator $authenticator,
        private readonly ChannelGate $gate,
    ) {
    }

    public function authorize(Request $request): Response
    {
        $socketId = (string) $request->input('socket_id', '');
        $channel = (string) $request->input('channel_name', '');

        if ($socketId === '' || $channel === '') {
            return response()->json(['message' => 'socket_id and channel_name are required.'])->withStatus(422);
        }

        // The socket id is signed into the result, so a malformed one would
        // produce a signature that can never verify. Refusing here says why.
        if (preg_match('/^\d+\.\d+$/', $socketId) !== 1) {
            return response()->json(['message' => 'Malformed socket_id.'])->withStatus(422);
        }

        if (! $this->authenticator->isProtectedChannel($channel)) {
            return response()->json(['message' => 'That channel does not need authorization.'])->withStatus(400);
        }

        $application = $this->application();

        if ($application === null) {
            return response()->json(['message' => 'No socket application is configured.'])->withStatus(500);
        }

        $result = $this->gate->authorize($request, $channel);

        if ($result === null) {
            // 403 whether the user is not allowed or the channel has no
            // callback at all: the difference is a way to enumerate which
            // channels exist.
            return response()->json(['message' => 'Forbidden.'])->withStatus(403);
        }

        if (! $this->authenticator->isPresenceChannel($channel)) {
            return response()->json([
                'auth' => $this->authenticator->authFor($application, $socketId, $channel),
            ]);
        }

        // Presence: the callback's return value becomes the member's public
        // profile, visible to everyone else in the channel. Whatever it
        // returns is what the rest of the room sees, so it is the callback's
        // job — not this one's — to leave out anything private.
        $member = is_array($result) ? $result : ['user_id' => (string) $result];

        if (! isset($member['user_id'])) {
            return response()->json(['message' => 'A presence channel callback must return a user_id.'])->withStatus(500);
        }

        $signed = $this->authenticator->presenceAuth($application, $socketId, $channel, [
            'user_id' => (string) $member['user_id'],
            'user_info' => (array) ($member['user_info'] ?? array_diff_key($member, ['user_id' => null])),
        ]);

        return response()->json($signed);
    }

    private function application(): ?\LibxaSocket\Application
    {
        $configured = (string) (config('socket.default', '') ?? '');

        if ($configured !== '') {
            return $this->applications->findById($configured);
        }

        $all = $this->applications->all();

        return count($all) === 1 ? reset($all) : null;
    }
}
