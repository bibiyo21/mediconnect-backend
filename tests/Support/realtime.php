<?php

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Testing\TestResponse;

/*
 * Real-time fixtures. phpunit.xml keeps BROADCAST_CONNECTION=null so no test reaches a
 * WebSocket server; channel authorization needs a signing broadcaster, so a test switches
 * to the Reverb driver with throw-away keys (it signs locally and never opens a connection)
 * and registers routes/channels.php on it.
 */

const REALTIME_TEST_KEY = 'test-app-key';

function use_signing_broadcaster(): void
{
    Broadcast::purge('null');
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => REALTIME_TEST_KEY,
        'broadcasting.connections.reverb.secret' => 'test-app-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app-id',
        'broadcasting.connections.reverb.options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
    ]);
    app(BroadcastManager::class)->purge('reverb');

    require base_path('routes/channels.php');
}

/**
 * POSTs the Pusher handshake for a channel and returns the response.
 *
 * @param  array<string, string>  $headers
 */
function channel_auth(string $channel, array $headers = [], string $socketId = '1234.5678'): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/broadcasting/auth', ['socket_id' => $socketId, 'channel_name' => $channel], $headers);
}
