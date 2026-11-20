<?php

namespace App\Modules\Discovery\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Search result cache with generation invalidation (architecture.md §12.5):
 * keys are `search:{collection}:{gen}:{hash}`; any index write bumps the
 * collection's generation, so old keys are never read again and simply
 * expire. Holds public search results only.
 */
final class SearchCache
{
    public static function bump(string $collection): void
    {
        $key = "search:{$collection}:gen";
        if (Cache::add($key, 1, now()->addDays(30)) === false) {
            Cache::increment($key);
        }
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>  $params
     * @param  callable(): T  $compute
     * @return array{0: T, 1: bool} the value and whether it came from the cache
     */
    public function remember(string $collection, array $params, callable $compute): array
    {
        ksort($params);
        $gen = (int) Cache::get("search:{$collection}:gen", 0);
        $key = "search:{$collection}:{$gen}:".hash('sha256', (string) json_encode($params));
        if (Cache::has($key)) {
            return [Cache::get($key), true];
        }
        $value = $compute();
        Cache::put($key, $value, now()->addSeconds((int) config('search.cache_ttl_seconds')));

        return [$value, false];
    }
}
