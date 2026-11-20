<?php

namespace App\Modules\Discovery\Jobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes one search_analytics row and, for an empty non-degraded text query,
 * counts it in search_zero_results. Run by SearchAnalytics::flush() after the
 * response is sent; failures are reported and never surface to the patient.
 */
final class RecordSearchAnalytics
{
    /** @param array<string, scalar> $filters */
    public function __construct(
        private readonly string $collection,
        private readonly string $query,
        private readonly array $filters,
        private readonly int $resultCount,
        private readonly int $latencyMs,
        private readonly bool $cacheHit,
        private readonly bool $degraded,
    ) {}

    public function handle(): void
    {
        try {
            DB::table('search_analytics')->insert([
                'collection' => $this->collection, 'query_hash' => hash('sha256', $this->collection.'|'.$this->query), 'normalized_query' => $this->query,
                'filters' => json_encode((object) $this->filters), 'result_count' => $this->resultCount, 'latency_ms' => $this->latencyMs,
                'cache_hit' => $this->cacheHit, 'degraded' => $this->degraded, 'occurred_at' => now(),
            ]);
            if ($this->resultCount === 0 && $this->query !== '' && ! $this->degraded) {
                DB::statement(
                    'INSERT INTO search_zero_results (id, collection, normalized_query, occurrences, first_seen_at, last_seen_at, status, created_at, updated_at)
                     VALUES (?, ?, ?, 1, now(), now(), \'NEW\', now(), now())
                     ON CONFLICT (collection, normalized_query) DO UPDATE SET occurrences = search_zero_results.occurrences + 1, last_seen_at = now(), updated_at = now()',
                    [(string) Str::uuid7(), $this->collection, $this->query],
                );
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
