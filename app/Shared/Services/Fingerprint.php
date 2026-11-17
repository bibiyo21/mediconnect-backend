<?php

namespace App\Shared\Services;

/**
 * Keyed hash (HMAC-SHA256 with the application key) for values we need to
 * match or rate-limit on without storing them: IP addresses, OTP destinations,
 * one-time codes. Not reversible without the key.
 */
final class Fingerprint
{
    public static function of(string $value): string
    {
        return hash_hmac('sha256', $value, self::key());
    }

    public static function ip(?string $ip): ?string
    {
        return $ip === null || $ip === '' ? null : self::of('ip:'.$ip);
    }

    private static function key(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7)) : $key;
    }
}
