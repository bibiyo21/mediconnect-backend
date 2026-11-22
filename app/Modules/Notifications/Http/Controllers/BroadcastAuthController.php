<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Http\Requests\BroadcastAuthRequest;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;

/**
 * Authorizes a WebSocket channel subscription (architecture.md §15.2). Works for cookie sessions
 * (first-party web) and bearer tokens (native), like every protected route.
 *
 * The answer is the Pusher-protocol signature, not the API envelope: the client library reads
 * `auth` (and `channel_data` for presence channels) directly. A refusal is the usual 403 envelope.
 */
final class BroadcastAuthController
{
    /**
     * Authorize a channel subscription.
     *
     * @response array{auth: string, channel_data?: string}
     */
    #[Response(403, 'The channel does not exist or is not yours to join.', type: 'array{success: false, message: string, errors: object, code: string, request_id: string|null}')]
    public function __invoke(BroadcastAuthRequest $request): mixed
    {
        // Only a signing broadcaster can prove a subscription. The null and log drivers would
        // answer every request with an empty 200, so realtime is reported as unavailable instead.
        abort_unless(Broadcast::driver() instanceof PusherBroadcaster, 503, 'Realtime updates are not available.');

        if ($request->hasSession()) {
            $request->session()->reflash();
        }

        return Broadcast::auth($request);
    }
}
