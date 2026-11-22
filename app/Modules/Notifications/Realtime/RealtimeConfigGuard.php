<?php

namespace App\Modules\Notifications\Realtime;

use RuntimeException;

/** Refuses an unsafe real-time configuration at boot, so it cannot reach production by accident. */
final class RealtimeConfigGuard
{
    /**
     * @param  array<int, mixed>  $allowedOrigins  Reverb's allowed WebSocket origins (host names)
     */
    public static function assertSafe(string $environment, string $broadcastDriver, array $allowedOrigins): void
    {
        if ($environment === 'production' && $broadcastDriver === 'reverb' && in_array('*', $allowedOrigins, true)) {
            throw new RuntimeException('REVERB_ALLOWED_ORIGINS must list the first-party hosts in production; "*" is refused.');
        }
    }
}
