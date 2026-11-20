<?php

namespace Tests\Support;

use App\Shared\Contracts\SearchEngine;
use App\Shared\Search\SearchUnavailable;

/**
 * An in-memory search engine for tests: collections, aliases and documents,
 * with exact-match `field:=value` filters only. Search relevance is tested
 * against real Typesense in the live tests.
 */
final class InMemorySearchEngine implements SearchEngine
{
    /** @var array<string, array{schema: array<string, mixed>, docs: array<string, array<string, mixed>>}> */
    public array $collections = [];

    /** @var array<string, string> */
    public array $aliases = [];

    /** @var array<string, list<array<string, mixed>>> */
    public array $synonymSets = [];

    public bool $down = false;

    public function healthy(): bool
    {
        return ! $this->down;
    }

    public function createCollection(string $name, array $schema): void
    {
        $this->guard();
        $this->collections[$name] = ['schema' => $schema, 'docs' => []];
    }

    public function dropCollection(string $name): void
    {
        unset($this->collections[$name]);
    }

    public function collectionExists(string $name): bool
    {
        return isset($this->collections[$this->resolve($name)]);
    }

    public function aliasTarget(string $alias): ?string
    {
        return $this->aliases[$alias] ?? null;
    }

    public function pointAlias(string $alias, string $collection): void
    {
        $this->aliases[$alias] = $collection;
    }

    public function upsert(string $collection, array $documents): void
    {
        $this->guard();
        foreach ($documents as $doc) {
            $this->collections[$this->resolve($collection)]['docs'][(string) $doc['id']] = $doc;
        }
    }

    public function deleteWhere(string $collection, string $filterBy): void
    {
        $this->guard();
        $name = $this->resolve($collection);
        $this->collections[$name]['docs'] = array_filter($this->collections[$name]['docs'] ?? [], fn (array $d) => ! $this->matches($d, $filterBy));
    }

    public function search(string $collection, array $params): array
    {
        $this->guard();
        $docs = array_values(array_filter($this->collections[$this->resolve($collection)]['docs'] ?? [], fn (array $d) => ! isset($params['filter_by']) || $this->matches($d, (string) $params['filter_by'])));

        return ['found' => count($docs), 'hits' => array_map(fn (array $d) => ['document' => $d], $docs)];
    }

    public function putSynonymSet(string $name, array $items): void
    {
        $this->guard();
        $this->synonymSets[$name] = $items;
    }

    /** @return array<string, array<string, mixed>> documents behind an alias or name */
    public function documents(string $collection): array
    {
        return $this->collections[$this->resolve($collection)]['docs'] ?? [];
    }

    private function resolve(string $name): string
    {
        return $this->aliases[$name] ?? $name;
    }

    /** Supports `a:=x && b:=y` exact matches. */
    private function matches(array $doc, string $filter): bool
    {
        foreach (explode('&&', $filter) as $clause) {
            [$field, $value] = array_map('trim', explode(':=', $clause, 2)) + [1 => ''];
            $actual = $doc[$field] ?? null;
            $value = trim($value, '`');
            if (is_array($actual) ? ! in_array($value, $actual, true) : (string) $actual !== $value) {
                return false;
            }
        }

        return true;
    }

    private function guard(): void
    {
        if ($this->down) {
            throw SearchUnavailable::because('in-memory engine is down');
        }
    }
}
