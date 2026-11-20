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

    /** @var list<array<string, mixed>> parameters of every search, for assertions */
    public array $searches = [];

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
        $this->searches[] = $params;
        $q = mb_strtolower((string) ($params['q'] ?? '*'));
        $fields = explode(',', (string) ($params['query_by'] ?? ''));
        $docs = array_values(array_filter($this->collections[$this->resolve($collection)]['docs'] ?? [], function (array $d) use ($params, $q, $fields) {
            if (isset($params['filter_by']) && ! $this->matches($d, (string) $params['filter_by'])) {
                return false;
            }
            if ($q === '*' || $q === '') {
                return true;
            }
            foreach ($fields as $f) {
                foreach ((array) ($d[$f] ?? []) as $value) {
                    if (str_contains(mb_strtolower((string) $value), $q)) {
                        return true;
                    }
                }
            }

            return false;
        }));
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = (int) ($params['per_page'] ?? 10);

        if (isset($params['group_by'])) {
            $groups = [];
            foreach ($docs as $d) {
                $groups[(string) $d[$params['group_by']]][] = ['document' => $d];
            }
            $grouped = array_map(fn ($hits, $key) => ['group_key' => [$key], 'hits' => $hits], array_values($groups), array_keys($groups));

            return ['found' => count($grouped), 'grouped_hits' => array_slice($grouped, ($page - 1) * $perPage, $perPage), 'facet_counts' => []];
        }

        return ['found' => count($docs), 'hits' => array_map(fn (array $d) => ['document' => $d], array_slice($docs, ($page - 1) * $perPage, $perPage))];
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

    /** Supports `:=` (also on arrays), `:!=[...]`, `:>=`, `:<=` joined with &&; geo clauses are ignored. */
    private function matches(array $doc, string $filter): bool
    {
        foreach (explode('&&', $filter) as $clause) {
            $clause = trim($clause);
            if (str_starts_with($clause, 'location:(')) {
                continue;
            }
            if (preg_match('/^(\w+):!=\[(.*)\]$/', $clause, $m) === 1) {
                $excluded = array_map(fn ($v) => trim(trim($v), '`'), explode(',', $m[2]));
                if (in_array((string) ($doc[$m[1]] ?? ''), $excluded, true)) {
                    return false;
                }

                continue;
            }
            if (preg_match('/^(\w+):(>=|<=)(\d+)$/', $clause, $m) === 1) {
                $actual = $doc[$m[1]] ?? null;
                if ($actual === null || ($m[2] === '>=' ? $actual < (int) $m[3] : $actual > (int) $m[3])) {
                    return false;
                }

                continue;
            }
            [$field, $value] = array_map('trim', explode(':=', $clause, 2)) + [1 => ''];
            $actual = $doc[$field] ?? null;
            $value = trim($value, '`');
            if (is_bool($actual)) {
                $actual = $actual ? 'true' : 'false';
            }
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
