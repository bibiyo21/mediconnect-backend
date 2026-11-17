<?php

use Illuminate\Support\Facades\Artisan;

/*
 * The committed openapi/openapi.json is the API contract (C-4, D-33):
 * mediconnect-shared generates the API client from it. This fails whenever a
 * route, Form Request or response changes without the contract being
 * regenerated:  docker compose run --rm api php artisan scramble:export
 */

it('keeps the committed OpenAPI contract in sync with the code', function () {
    $fresh = storage_path('framework/testing/openapi.json');
    @mkdir(dirname($fresh), 0775, true);

    Artisan::call('scramble:export', ['--path' => $fresh]);

    $committed = base_path('openapi/openapi.json');
    expect(file_exists($committed))->toBeTrue('openapi/openapi.json is missing: run php artisan scramble:export');

    expect(json_decode((string) file_get_contents($fresh), true))
        ->toEqual(json_decode((string) file_get_contents($committed), true), 'openapi/openapi.json is stale: run php artisan scramble:export and commit it');
});

it('publishes an environment-independent contract with the auth schemes', function () {
    $doc = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true);

    expect($doc['servers'])->toBe([['url' => '/api/v1']])
        ->and(array_keys($doc['components']['securitySchemes']))->toBe(['bearer', 'session'])
        ->and($doc['paths'])->toHaveKeys(['/auth/login', '/auth/register', '/auth/refresh', '/health']);
});
