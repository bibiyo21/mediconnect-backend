<?php

use App\Modules\Discovery\Services\CircuitBreaker;
use App\Modules\Discovery\Services\SearchIndexer;
use App\Modules\Doctors\Models\DoctorSpecialty;
use App\Shared\Contracts\SearchEngine;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Str;

/*
 * Increment 3.3: the search API. The in-memory engine covers the plumbing
 * (grouping, filters, cache, breaker, fallback, response shape, privacy) in
 * every environment; relevance (typos, synonyms, distance) is checked against
 * live Typesense at the end of this file.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    $this->engine = app(SearchEngine::class);
});

it('finds doctors grouped by doctor, with their locations, fees and public fields only', function () {
    $location = directory_clinic();
    $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);
    directory_doctor('ACTIVE', 'Dr. Ben Reyes');

    $response = $this->getJson('/api/v1/search/doctors?q=santos')->assertOk();
    $result = $response->json('data.0');

    expect($response->json('meta.found'))->toBe(1)
        ->and($response->json('meta.degraded'))->toBeFalse()
        ->and($result['doctor_id'])->toBe($doctor->id)
        ->and($result['offers_online'])->toBeTrue()
        ->and($result['fee_from']['formatted'])->toBe('₱500.00')
        ->and($result['locations'][0]['clinic_name'])->toBe('Santos Family Clinic');
    expect($response->getContent())->not->toContain((string) $doctor->prc_license_number_encrypted)->not->toContain('user_id');
});

it('applies filters: specialty, city, consultation type and fee', function () {
    $makati = directory_clinic('Santos Family Clinic', 'Makati');
    $a = directory_doctor('ACTIVE', 'Dr. Ana Santos', $makati);
    $b = directory_doctor('ACTIVE', 'Dr. Ben Reyes');
    $specialty = DoctorSpecialty::where('doctor_profile_id', $a->id)->where('verification_status', 'CONFIRMED')->value('specialty_id');

    expect($this->getJson('/api/v1/search/doctors?city=Makati')->json('data.*.doctor_id'))->toBe([$a->id]);
    expect($this->getJson('/api/v1/search/doctors?consultation_type=ONLINE')->json('meta.found'))->toBe(2);
    expect($this->getJson("/api/v1/search/doctors?specialty={$specialty}")->json('meta.found'))->toBe(2);
    expect($this->getJson('/api/v1/search/doctors?fee_min=60000')->json('data.*.doctor_id'))->toBe([$a->id]);
    $this->getJson('/api/v1/search/doctors?lat=99&lng=121')->assertStatus(422)->assertJsonValidationErrors('lat');
    $this->getJson('/api/v1/search/doctors?cursor=nonsense')->assertStatus(422)->assertJsonValidationErrors('cursor');
});

it('pages with an opaque cursor', function () {
    foreach (range(1, 3) as $i) {
        directory_doctor('ACTIVE', "Dr. Page {$i}");
    }

    $first = $this->getJson('/api/v1/search/doctors?per_page=2')->assertOk();
    $cursor = $first->json('meta.next_cursor');
    $second = $this->getJson('/api/v1/search/doctors?per_page=2&cursor='.$cursor)->assertOk();

    expect($first->json('data'))->toHaveCount(2)->and($cursor)->toBeString()
        ->and($second->json('data'))->toHaveCount(1)->and($second->json('meta.next_cursor'))->toBeNull();
});

it('caches results and stops serving stale ones after an index write', function () {
    directory_doctor('ACTIVE', 'Dr. Ana Santos');
    $this->getJson('/api/v1/search/doctors?q=santos')->assertJsonPath('meta.cache_hit', false);
    $this->getJson('/api/v1/search/doctors?q=Santos%20%20')->assertJsonPath('meta.cache_hit', true); // normalized to the same key

    directory_doctor('ACTIVE', 'Dr. Carla Santos'); // an index write bumps the generation

    $fresh = $this->getJson('/api/v1/search/doctors?q=santos')->assertJsonPath('meta.cache_hit', false);
    expect($fresh->json('meta.found'))->toBe(2);
});

it('falls back to PostgreSQL when the engine is down, flagged degraded, and opens the breaker', function () {
    $location = directory_clinic();
    $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);
    directory_doctor('VERIFIED', 'Dr. Santos Hidden');
    $this->engine->down = true;

    $response = $this->getJson('/api/v1/search/doctors?q=santos')->assertOk();

    expect($response->json('meta.degraded'))->toBeTrue()
        ->and($response->json('data.*.doctor_id'))->toBe([$doctor->id])
        ->and($response->json('data.0.locations.0.clinic_name'))->toBe('Santos Family Clinic')
        ->and(app(CircuitBreaker::class)->isOpen())->toBeTrue();

    // While the breaker is open, the engine is not even tried.
    $this->engine->down = false;
    $calls = count($this->engine->searches);
    $this->getJson('/api/v1/search/doctors?q=santos')->assertJsonPath('meta.degraded', true);
    expect(count($this->engine->searches))->toBe($calls);

    app(CircuitBreaker::class)->reset();
    $this->getJson('/api/v1/search/doctors?q=santos')->assertJsonPath('meta.degraded', false);
});

it('matches by specialty name and synonym in the fallback', function () {
    $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos');
    $specialty = DoctorSpecialty::with('specialty')->where('doctor_profile_id', $doctor->id)->where('verification_status', 'CONFIRMED')->firstOrFail()->specialty;
    $this->engine->down = true;

    expect($this->getJson('/api/v1/search/doctors?q='.urlencode(Str::lower(substr($specialty->name, 0, 5))))->json('data.*.doctor_id'))->toBe([$doctor->id]);
    if ($specialty->synonyms !== []) {
        expect($this->getJson('/api/v1/search/doctors?q='.urlencode($specialty->synonyms[0]))->json('data.*.doctor_id'))->toBe([$doctor->id]);
    }
});

it('searches clinics and suggests specialties, with fallbacks', function () {
    $location = directory_clinic('Santos Family Clinic', 'Makati');
    directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);

    $clinics = $this->getJson('/api/v1/search/clinics?q=santos')->assertOk();
    expect($clinics->json('data.0.clinic_location_id'))->toBe($location->id)->and($clinics->json('data.0.doctor_count'))->toBe(1);

    // A cached answer is still served while the engine is down; a new query falls back.
    $this->engine->down = true;
    expect($this->getJson('/api/v1/search/clinics?q=santos')->json('meta.cache_hit'))->toBeTrue();
    expect($this->getJson('/api/v1/search/clinics?q=family')->json('meta.degraded'))->toBeTrue();
    app(CircuitBreaker::class)->reset();
    $this->engine->down = true;
    $suggest = $this->getJson('/api/v1/search/specialties?q=card')->assertOk();
    expect($suggest->json('meta.degraded'))->toBeTrue()->and($suggest->json('data.0.name'))->toStartWith('Cardio');
});

it('is public and rate limited', function () {
    $this->getJson('/api/v1/search/doctors')->assertOk();
    $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/search/doctors');
    expect($route->gatherMiddleware())->toContain('throttle:60,1');
});

it('searches with typos, synonyms, facets and distance on live Typesense', function () {
    $live = live_engine();
    if ($live === null) {
        $this->markTestSkipped('Typesense is not reachable here.');
    }
    config(['search.prefix' => 'mc_test_live_'.strtolower(Str::random(5))]);
    app()->instance(SearchEngine::class, $live);
    SearchIndexer::forgetInstalled();
    $prefix = (string) config('search.prefix');

    try {
        $location = directory_clinic('Santos Family Clinic', 'Makati');
        $location->forceFill(['latitude' => 14.5547, 'longitude' => 121.0244])->save();
        $doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $location);
        $far = directory_doctor('ACTIVE', 'Dr. Ben Reyes');
        $specialty = DoctorSpecialty::with('specialty')->where('doctor_profile_id', $doctor->id)->where('verification_status', 'CONFIRMED')->firstOrFail()->specialty;

        expect($this->getJson('/api/v1/search/doctors?q=Snatos')->json('data.0.doctor_id'))->toBe($doctor->id); // typo
        $bySpecialty = $this->getJson('/api/v1/search/doctors?q='.urlencode(Str::lower($specialty->name)))->json();
        expect($bySpecialty['meta']['found'])->toBe(2)
            ->and($bySpecialty['meta']['facets']['city'] ?? [])->toHaveKey('Makati');
        $near = $this->getJson('/api/v1/search/doctors?lat=14.5560&lng=121.0230&radius_km=5&sort=distance')->json();
        expect(array_column($near['data'], 'doctor_id'))->toBe([$doctor->id])
            ->and($near['data'][0]['locations'][0]['distance_km'])->toBeLessThan(1.0);
        expect($far->id)->not->toBeIn(array_column($near['data'], 'doctor_id'));
    } finally {
        foreach (['doctors', 'clinics', 'specialties'] as $name) {
            if (($target = $live->aliasTarget($prefix.'_'.$name)) !== null) {
                $live->dropCollection($target);
            }
        }
    }
});
