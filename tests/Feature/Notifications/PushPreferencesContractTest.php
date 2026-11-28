<?php

use App\Modules\Notifications\Preferences\PushPreferenceGroups;

/*
 * HAG-38: the committed OpenAPI document must describe the PUSH switches as an object with exactly the three groups as
 * required booleans, the 403 of mfa.satisfied, the 429 of the throttle, and a request body with no extra keys. The shared
 * client is generated from this file, so a wrong shape here reaches the apps.
 */

/** @return array<string, mixed> */
function pp_contract(): array
{
    return json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true, 512, JSON_THROW_ON_ERROR);
}

it('types the PUSH switches as an object with exactly the three groups, each a required boolean', function () {
    $push = pp_contract()['components']['schemas']['PushPreferencesResource']['properties']['push'];

    expect($push['type'])->toBe('object')
        ->and(array_keys($push['properties']))->toBe(PushPreferenceGroups::keys())
        ->and($push['required'])->toBe(PushPreferenceGroups::keys());

    foreach ($push['properties'] as $group => $schema) {
        expect($schema)->toBe(['type' => 'boolean'], "group {$group}");
    }
});

it('types the PUT body with the same three groups', function () {
    $push = pp_contract()['components']['schemas']['UpdatePushPreferencesRequest']['properties']['push'];

    expect(array_keys($push['properties']))->toBe(PushPreferenceGroups::keys())
        ->and($push['required'])->toBe(PushPreferenceGroups::keys());
});

it('documents 403, 401 and 429 on both preference operations', function () {
    $paths = pp_contract()['paths']['/notifications/preferences'];

    foreach (['get', 'put'] as $method) {
        expect(array_map('intval', array_keys($paths[$method]['responses'])))->toContain(401, 403, 429);
    }
});
