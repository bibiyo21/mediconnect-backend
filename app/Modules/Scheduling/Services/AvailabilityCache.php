<?php

namespace App\Modules\Scheduling\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Short-lived cache of public availability reads (§25 `doctor:availability:*`),
 * invalidated by a per-doctor generation bumped on every refresh. Holds public
 * slot times only; holds always revalidate against PostgreSQL (§11.6).
 */
final class AvailabilityCache
{
    private const TTL_SECONDS = 60;

    public static function bump(string $doctorId): void
    {
        $key = "doctor:availability:{$doctorId}:gen";
        if (Cache::add($key, 1, now()->addDays(7)) === false) {
            Cache::increment($key);
        }
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>  $params
     * @param  \Closure(): T  $compute
     * @return T
     */
    public static function remember(string $doctorId, array $params, \Closure $compute): mixed
    {
        ksort($params);
        $gen = (int) Cache::get("doctor:availability:{$doctorId}:gen", 0);

        return Cache::remember("doctor:availability:{$doctorId}:{$gen}:".hash('sha256', (string) json_encode($params)), self::TTL_SECONDS, $compute);
    }
}
