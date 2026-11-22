<?php

namespace App\Modules\Notifications\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Pusher-protocol subscription handshake. Only plain private channels are authorized here
 * (no presence channels until a module needs one, and no private-encrypted- channels, which
 * would sign a name the callbacks never match); whether this user may join the named channel
 * is decided by the channel callback in routes/channels.php.
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
            'socket_id' => ['required', 'string', 'max:64', 'regex:/^\d+\.\d+$/'],
            /** The channel to join, with its prefix, like `private-user.{id}`. */
            'channel_name' => ['required', 'string', 'max:190', 'regex:/^private-(?!encrypted-)[A-Za-z0-9_.\-]+$/'],
        ];
    }
}
