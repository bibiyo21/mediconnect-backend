<?php

/*
 * Real-time broadcasting (CLAUDE.md §19, architecture.md §15.2).
 *
 * Reverb speaks the Pusher protocol. Only private and presence channels exist;
 * every subscription is authorized server-side by POST /api/v1/broadcasting/auth
 * (routes/channels.php). Broadcasts are hints: PostgreSQL stays the truth and
 * clients refetch after a reconnect.
 *
 * The default is "null" so a fresh checkout, CI and tests never try to reach a
 * Reverb server. Local development sets BROADCAST_CONNECTION=reverb (README).
 * "log" writes events to the log (payloads carry identifiers only, never clinical data).
 */
return [
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'app_id' => env('REVERB_APP_ID'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle options for the server-to-Reverb publish call: fail fast, never hold a worker.
                'timeout' => 3,
                'connect_timeout' => 2,
            ],
        ],

        'log' => ['driver' => 'log'],

        'null' => ['driver' => 'null'],
    ],
];
