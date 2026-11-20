<?php

namespace App\Modules\Discovery\Services;

use App\Shared\Contracts\SearchEngine;

/**
 * Writes one aggregate's documents: upsert the current set first, then delete
 * any document of that aggregate not in it. Searches never see the aggregate
 * disappear mid-update, and a stale or repeated job converges to current state.
 */
final class SearchIndexer
{
    private static bool $installed = false;

    public function __construct(private readonly SearchEngine $engine, private readonly SearchCollections $collections) {}

    /** @param list<array<string, mixed>> $documents */
    public function replace(string $collection, string $keyField, string $keyValue, array $documents, ?string $target = null): void
    {
        $this->ensureInstalled();
        $name = $target ?? $this->collections->alias($collection);
        $this->engine->upsert($name, $documents);
        $keep = array_map(fn (array $d) => '`'.$d['id'].'`', $documents);
        $filter = $keyField.':=`'.$keyValue.'`'.($keep === [] ? '' : ' && id:!=['.implode(',', $keep).']');
        $this->engine->deleteWhere($name, $filter);
        SearchCache::bump($collection);
    }

    /** Collections are created on deploy (search:install); this covers a fresh environment. */
    private function ensureInstalled(): void
    {
        if (! self::$installed) {
            $this->collections->install();
            self::$installed = true;
        }
    }

    /** For tests that swap the engine. */
    public static function forgetInstalled(): void
    {
        self::$installed = false;
    }
}
