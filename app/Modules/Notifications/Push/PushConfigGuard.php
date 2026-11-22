<?php

namespace App\Modules\Notifications\Push;

use RuntimeException;

/** Refuses an unsafe or unknown push configuration at boot, so a LOCAL driver cannot reach production by accident. */
final class PushConfigGuard
{
    public const DRIVERS = ['log', 'disabled'];

    public static function assertSafe(string $environment, string $driver): void
    {
        if (! in_array($driver, self::DRIVERS, true)) {
            throw new RuntimeException("Unknown PUSH_DRIVER [{$driver}]; use one of: ".implode(', ', self::DRIVERS).'.');
        }
        if ($environment === 'production' && $driver === 'log') {
            throw new RuntimeException('PUSH_DRIVER=log is for LOCAL use and is refused in production; use "disabled" until real push delivery exists.');
        }
    }
}
