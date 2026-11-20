<?php

namespace App\Shared\Search;

use App\Shared\Contracts\SearchEngine;
use Http\Client\Exception as HttpException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Client\ClientExceptionInterface;
use Throwable;
use Typesense\Client;
use Typesense\Exceptions\HTTPStatus0Error;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\ServerError;
use Typesense\Exceptions\ServiceUnavailable;
use Typesense\Exceptions\Timeout;
use Typesense\Exceptions\TypesenseClientError;

/**
 * Typesense implementation (self-hosted, D-P1-1). Network and server errors
 * become SearchUnavailable so callers can fall back to PostgreSQL; client
 * errors (bad schema or query) are programming errors and are rethrown.
 */
final class TypesenseEngine implements SearchEngine
{
    public function __construct(private readonly Client $client, private readonly string $baseUrl, private readonly string $apiKey) {}

    public function healthy(): bool
    {
        try {
            return ($this->client->health->retrieve()['ok'] ?? false) === true;
        } catch (Throwable) {
            return false;
        }
    }

    public function createCollection(string $name, array $schema): void
    {
        $this->call(fn () => $this->client->collections->create(['name' => $name, ...$schema]));
    }

    public function dropCollection(string $name): void
    {
        $this->call(function () use ($name) {
            try {
                $this->client->collections[$name]->delete();
            } catch (ObjectNotFound) {
                // already gone
            }
        });
    }

    public function collectionExists(string $name): bool
    {
        return $this->call(function () use ($name) {
            try {
                $this->client->collections[$name]->retrieve();

                return true;
            } catch (ObjectNotFound) {
                return false;
            }
        });
    }

    public function aliasTarget(string $alias): ?string
    {
        return $this->call(function () use ($alias) {
            try {
                return (string) $this->client->aliases[$alias]->retrieve()['collection_name'];
            } catch (ObjectNotFound) {
                return null;
            }
        });
    }

    public function pointAlias(string $alias, string $collection): void
    {
        $this->call(fn () => $this->client->aliases->upsert($alias, ['collection_name' => $collection]));
    }

    public function upsert(string $collection, array $documents): void
    {
        if ($documents === []) {
            return;
        }
        $results = $this->call(fn () => $this->client->collections[$collection]->documents->import($documents, ['action' => 'upsert']));
        foreach ((array) $results as $result) {
            if (is_array($result) && ($result['success'] ?? true) === false) {
                throw new \UnexpectedValueException('Document rejected by the search engine: '.mb_substr((string) ($result['error'] ?? 'unknown'), 0, 200));
            }
        }
    }

    public function deleteWhere(string $collection, string $filterBy): void
    {
        $this->call(fn () => $this->client->collections[$collection]->documents->delete(['filter_by' => $filterBy]));
    }

    public function search(string $collection, array $params): array
    {
        return (array) $this->call(fn () => $this->client->collections[$collection]->documents->search($params));
    }

    public function putSynonymSet(string $name, array $items): void
    {
        // Typesense 30 synonym sets; the PHP client (v5) predates them, so plain HTTP.
        try {
            $response = Http::withHeaders(['X-TYPESENSE-API-KEY' => $this->apiKey])->timeout(5)
                ->put($this->baseUrl.'/synonym_sets/'.rawurlencode($name), ['items' => $items]);
        } catch (ConnectionException $e) {
            throw SearchUnavailable::because('network', $e);
        }
        if ($response->serverError()) {
            throw SearchUnavailable::because('synonym set: HTTP '.$response->status());
        }
        $response->throw();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function call(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (ServiceUnavailable|ServerError|Timeout|HTTPStatus0Error|HttpException $e) {
            throw SearchUnavailable::because($e::class, $e);
        } catch (TypesenseClientError $e) {
            throw $e;
        } catch (ClientExceptionInterface $e) {
            throw SearchUnavailable::because('network', $e);
        }
    }
}
