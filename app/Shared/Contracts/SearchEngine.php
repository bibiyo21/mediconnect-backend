<?php

namespace App\Shared\Contracts;

use App\Shared\Search\SearchUnavailable;

/**
 * The search engine (architecture.md §12; Typesense). PostgreSQL stays the
 * source of truth: everything here can be rebuilt from it. Callers treat
 * SearchUnavailable as "use the PostgreSQL fallback", never as data loss.
 */
interface SearchEngine
{
    public function healthy(): bool;

    /** @param array<string, mixed> $schema the engine's collection schema, without the name */
    public function createCollection(string $name, array $schema): void;

    public function dropCollection(string $name): void;

    public function collectionExists(string $name): bool;

    /** The collection an alias points to, or null when there is no alias. */
    public function aliasTarget(string $alias): ?string;

    public function pointAlias(string $alias, string $collection): void;

    /**
     * Inserts or replaces documents by `id`.
     *
     * @param  list<array<string, mixed>>  $documents
     *
     * @throws SearchUnavailable
     */
    public function upsert(string $collection, array $documents): void;

    /** Deletes every document matching an engine filter, for example `doctor_id:=abc`. */
    public function deleteWhere(string $collection, string $filterBy): void;

    /**
     * @param  array<string, mixed>  $params  engine search parameters (q, query_by, filter_by, sort_by, page, per_page, group_by, ...)
     * @return array<string, mixed> the engine's raw result
     *
     * @throws SearchUnavailable
     */
    public function search(string $collection, array $params): array;

    /**
     * Replaces a synonym set. Collections opt in through `synonym_sets` in their schema.
     *
     * @param  list<array{id: string, synonyms: list<string>, root?: string}>  $items
     */
    public function putSynonymSet(string $name, array $items): void;
}
