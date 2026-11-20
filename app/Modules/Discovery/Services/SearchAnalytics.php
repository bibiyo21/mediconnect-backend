<?php

namespace App\Modules\Discovery\Services;

use App\Modules\Discovery\Jobs\RecordSearchAnalytics;

/**
 * Records each search for latency, cache-hit and zero-result reporting
 * (architecture.md §12.6). A job runs once after the response is sent, so it
 * adds no latency, and a failure there never breaks search. No user identity is kept:
 * the normalized text, a hash, filter names (coordinates are reduced to
 * `geo: true`) and numbers.
 */
final class SearchAnalytics
{
    /** @var list<RecordSearchAnalytics> */
    private static array $pending = [];

    /** Writes everything recorded during this request. */
    public static function flush(): void
    {
        $jobs = self::$pending;
        self::$pending = [];
        foreach ($jobs as $job) {
            $job->handle();
        }
    }

    /** Filter values worth keeping for reporting; everything else is reduced to its name. */
    private const KEEP_VALUES = ['consultation_type', 'sort', 'city', 'province', 'language', 'gender'];

    /** @param array<string, mixed> $criteria */
    public function record(string $collection, array $criteria, int $resultCount, int $latencyMs, bool $cacheHit, bool $degraded): void
    {
        $query = QueryNormalizer::key(isset($criteria['q']) ? (string) $criteria['q'] : null);
        $filters = [];
        foreach ($criteria as $name => $value) {
            if (in_array($name, ['q', 'page', 'per_page', 'cursor', 'lng', 'radius_km'], true) || $value === null) {
                continue;
            }
            if ($name === 'lat') {
                $filters['geo'] = true;
            } else {
                $filters[$name] = in_array($name, self::KEEP_VALUES, true) ? mb_substr((string) $value, 0, 50) : true;
            }
        }
        ksort($filters);

        self::$pending[] = new RecordSearchAnalytics($collection, $query, $filters, $resultCount, $latencyMs, $cacheHit, $degraded);
        if (! app()->bound('search.analytics.flush')) {
            // Registered once per application instance; flush() drains the list, so a callback
            // that runs again on a long-lived app (tests, Octane) writes nothing twice.
            app()->instance('search.analytics.flush', true);
            app()->terminating(fn () => self::flush());
        }
    }
}
