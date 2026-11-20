<?php

namespace App\Modules\Discovery\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Opens after a search engine failure, so for a short while every request goes
 * straight to the PostgreSQL fallback instead of waiting on a dead engine
 * (architecture.md §12.6). Closes on its own when the window ends.
 */
final class CircuitBreaker
{
    private const KEY = 'search:breaker:open';

    public function isOpen(): bool
    {
        return Cache::has(self::KEY);
    }

    public function trip(): void
    {
        Cache::put(self::KEY, true, now()->addSeconds((int) config('search.breaker_open_seconds')));
    }

    public function reset(): void
    {
        Cache::forget(self::KEY);
    }
}
