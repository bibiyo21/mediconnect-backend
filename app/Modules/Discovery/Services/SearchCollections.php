<?php

namespace App\Modules\Discovery\Services;

use App\Shared\Contracts\SearchEngine;

/**
 * Names and lifecycle of the search collections. Readers and writers always use
 * the alias (`mc_doctors`); the alias points at a versioned collection
 * (`mc_doctors_v1_1795133950`), so a rebuild can swap without downtime.
 */
final class SearchCollections
{
    public function __construct(private readonly SearchEngine $engine) {}

    /** @return list<string> */
    public function names(): array
    {
        return array_keys((array) config('search.collections'));
    }

    public function alias(string $name): string
    {
        $this->assertKnown($name);

        return config('search.prefix').'_'.$name;
    }

    /** Creates a fresh versioned collection for $name (not yet behind the alias). */
    public function createVersion(string $name): string
    {
        $this->assertKnown($name);
        $config = (array) config("search.collections.{$name}");
        $collection = $this->alias($name).'_v'.$config['version'].'_'.now()->format('YmdHisv');
        $this->engine->createCollection($collection, (array) $config['schema']);

        return $collection;
    }

    /**
     * Makes sure every collection exists behind its alias (an empty one the first
     * time). Returns the collections that were created.
     *
     * @return list<string>
     */
    public function install(): array
    {
        $this->ensureSynonymSet();
        $created = [];
        foreach ($this->names() as $name) {
            $target = $this->engine->aliasTarget($this->alias($name));
            if ($target === null || ! $this->engine->collectionExists($target)) {
                $collection = $this->createVersion($name);
                $this->engine->pointAlias($this->alias($name), $collection);
                $created[] = $collection;
            }
        }

        return $created;
    }

    /** Points the alias at $collection and drops the collection it pointed at before. */
    public function swap(string $name, string $collection): ?string
    {
        $previous = $this->engine->aliasTarget($this->alias($name));
        $this->engine->pointAlias($this->alias($name), $collection);
        SearchCache::bump($name);
        if ($previous !== null && $previous !== $collection) {
            $this->engine->dropCollection($previous);
        }

        return $previous;
    }

    /** The synonym set must exist before a collection that references it. */
    public function ensureSynonymSet(): void
    {
        $this->engine->putSynonymSet((string) config('search.synonym_set'), $this->synonymItems());
    }

    /**
     * Synonym items from search_synonyms (3.4); none until then.
     *
     * @return list<array{id: string, synonyms: list<string>, root?: string}>
     */
    public function synonymItems(): array
    {
        return [];
    }

    private function assertKnown(string $name): void
    {
        if (! in_array($name, $this->names(), true)) {
            throw new \InvalidArgumentException("Unknown search collection: {$name}");
        }
    }
}
