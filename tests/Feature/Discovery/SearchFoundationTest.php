<?php

use App\Modules\Discovery\Services\SearchCollections;
use App\Shared\Contracts\SearchEngine;
use App\Shared\Search\SearchUnavailable;
use App\Shared\Search\TypesenseEngine;
use Illuminate\Support\Str;
use Typesense\Client;

/*
 * Increment 3.1: the search foundation. The in-memory engine covers the
 * collection lifecycle; the live tests run against the local Typesense and are
 * skipped where it is not reachable (CI has none).
 */

it('installs every collection behind its alias, once', function () {
    $collections = app(SearchCollections::class);
    $engine = app(SearchEngine::class);

    $created = $collections->install();

    expect($created)->toHaveCount(3)
        ->and($engine->aliasTarget('mc_test_doctors'))->toStartWith('mc_test_doctors_v1_')
        ->and($collections->install())->toBe([]);
});

it('swaps an alias to a new version and drops the old one', function () {
    $collections = app(SearchCollections::class);
    $engine = app(SearchEngine::class);
    $collections->install();
    $old = $engine->aliasTarget('mc_test_clinics');
    $this->travel(1)->seconds();

    $new = $collections->createVersion('clinics');
    $previous = $collections->swap('clinics', $new);

    expect($previous)->toBe($old)
        ->and($engine->aliasTarget('mc_test_clinics'))->toBe($new)
        ->and($engine->collectionExists((string) $old))->toBeFalse();
});

it('never indexes private fields: the doctor schema has none', function () {
    $fields = collect(config('search.collections.doctors.schema.fields'))->pluck('name')->implode(',');
    foreach (['licen', 'prc', 'email', 'phone', 'mobile', 'user_id', 'credential', 'birth'] as $private) {
        expect($fields)->not->toContain($private);
    }
});

it('reports search on /ready without failing readiness when it is down', function () {
    app(SearchEngine::class)->down = true;

    $this->getJson('/api/v1/ready')->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonPath('data.checks.search', 'degraded');
});

it('turns an unreachable engine into SearchUnavailable', function () {
    $engine = new TypesenseEngine(new Client(['api_key' => 'x', 'nodes' => [['host' => '127.0.0.1', 'port' => '1', 'protocol' => 'http']], 'connection_timeout_seconds' => 0.5, 'num_retries' => 0]), 'http://127.0.0.1:1', 'x');

    expect($engine->healthy())->toBeFalse();
    $engine->search('anything', ['q' => '*']);
})->throws(SearchUnavailable::class);

it('creates, searches with typos and synonyms, and drops a collection on live Typesense', function () {
    $engine = live_engine();
    if ($engine === null) {
        $this->markTestSkipped('Typesense is not reachable here.');
    }
    $set = 'probe_'.Str::lower(Str::random(6));
    $name = 'mc_test_probe_'.Str::lower(Str::random(6));
    $engine->putSynonymSet($set, [['id' => 'pedia', 'synonyms' => ['pedia', 'pediatrics']]]);
    $engine->createCollection($name, ['fields' => [['name' => 'name', 'type' => 'string'], ['name' => 'specialties', 'type' => 'string[]']], 'synonym_sets' => [$set]]);

    try {
        $engine->upsert($name, [['id' => '1', 'name' => 'Dr. Ana Santos', 'specialties' => ['Pediatrics']], ['id' => '2', 'name' => 'Dr. Ben Reyes', 'specialties' => ['Cardiology']]]);

        $typo = $engine->search($name, ['q' => 'Snatos', 'query_by' => 'name']);
        $synonym = $engine->search($name, ['q' => 'pedia', 'query_by' => 'specialties']);

        expect($typo['hits'][0]['document']['id'])->toBe('1')
            ->and($synonym['hits'][0]['document']['id'])->toBe('1');
        $engine->deleteWhere($name, 'id:=2');
        expect($engine->search($name, ['q' => '*', 'query_by' => 'name'])['found'])->toBe(1);
    } finally {
        $engine->dropCollection($name);
    }
    expect($engine->collectionExists($name))->toBeFalse();
});
