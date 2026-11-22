<?php

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Pusher-protocol subscription handshake. Only `presence-` channels are authorized: every
 * channel we publish on is a presence channel so Reverb can tie the connection to a user and
 * terminate it (increment 5.4b, M-3). The `private-` spelling (and private-encrypted-) is refused
 * with 422, because signing it would let a subscriber join without a user id. Whether this user
 * may join the named channel is decided by the channel callback in routes/channels.php.
 *
 * Patterns end in `\z`, not `$`: `$` also matches before a trailing newline, which the Pusher
 * library then rejects with a 500.
 */
final class BroadcastAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // signed in by middleware; the channel callback authorizes the subscription
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** The connection id the WebSocket server gave the client, like `1234.5678`. */
            'socket_id' => ['required', 'string', 'max:64', 'regex:/^\d+\.\d+\z/'],
            /** The channel to join, with its prefix, like `presence-user.{id}`. */
            'channel_name' => ['required', 'string', 'max:190', 'regex:/^presence-[A-Za-z0-9_.\-]+\z/'],
        ];
    }
}
