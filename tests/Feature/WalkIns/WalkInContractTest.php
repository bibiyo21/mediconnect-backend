<?php

use App\Modules\Rbac\Enums\Role;
use App\Shared\Enums\ErrorCode;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\Route;

/*
 * Increment 5.5a: the walk-in API contract. The committed OpenAPI document names every route and refusal, the request refuses
 * every field the server decides, the entry views carry exactly the keys each audience may see (a new field fails here), and each route
 * has its named limiter and, where the plan says so, the Idempotency-Key middleware.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    $this->doc = json_decode((string) file_get_contents(base_path('openapi/openapi.json')), true);
});

it('documents every walk-in route with its refusals', function () {
    $paths = $this->doc['paths'];

    foreach ([
        ['/walk-ins', 'post'], ['/walk-ins/queue', 'get'], ['/walk-ins/join-preview', 'get'], ['/walk-ins/search', 'post'], ['/walk-ins/{id}', 'get'],
        ['/walk-ins/{id}/arrive', 'post'], ['/walk-ins/{id}/cancel', 'post'], ['/walk-ins/queues/{id}/pause', 'post'], ['/walk-ins/queues/{id}/open', 'post'],
        ['/clinics/{clinicId}/doctor-assignments/{assignmentId}/walkin-policy', 'get'], ['/clinics/{clinicId}/doctor-assignments/{assignmentId}/walkin-policy', 'patch'],
    ] as [$path, $method]) {
        expect($paths)->toHaveKey($path)->and($paths[$path])->toHaveKey($method)->and($paths[$path][$method]['responses'])->toHaveKey('401');
    }
    expect($paths['/walk-ins']['post']['responses']['409']['description'])->toContain('QUEUE_NOT_STARTED')->toContain('REGISTRATION_NOT_ALLOWED')->toContain('CAPACITY_OVERRIDE_REQUIRED')
        ->toContain('ENTRY_ALREADY_LIVE')->toContain('DAILY_LIMIT_REACHED')->toContain('LATE_RISK_ACK_REQUIRED')->toContain('QUEUE_FULL')->toContain('QUEUE_CLOSED');
    expect($this->doc['components']['schemas']['ErrorEnvelope']['properties']['reason_code']['enum'])
        ->toContain('remote_registration_off', 'mobile_not_verified', 'doctor_unavailable', 'doctor_checked_out', 'no_walkin_hours_today', 'paused', 'cutoff_passed', 'live_entries_per_patient', 'patients_per_account_per_day')
        ->and($this->doc['components']['schemas']['ErrorEnvelope']['properties']['code']['enum'])
        ->toContain('QUEUE_NOT_STARTED', 'REGISTRATION_NOT_ALLOWED', 'CAPACITY_OVERRIDE_REQUIRED', 'ENTRY_ALREADY_LIVE', 'DAILY_LIMIT_REACHED', 'LATE_RISK_ACK_REQUIRED');
});

it('documents the 404, 400, header and impossible-422 details of the walk-in routes', function () {
    $paths = $this->doc['paths'];
    $names = fn ($op) => array_column($op['parameters'] ?? [], 'name');

    foreach ([['/walk-ins/{id}', 'get'], ['/walk-ins/{id}/arrive', 'post'], ['/walk-ins/{id}/cancel', 'post'], ['/walk-ins/queues/{id}/pause', 'post'], ['/walk-ins/queues/{id}/open', 'post']] as [$path, $method]) {
        expect($paths[$path][$method]['responses'])->toHaveKey('404');
    }
    foreach ([['/walk-ins', 'post'], ['/walk-ins/{id}/arrive', 'post'], ['/walk-ins/{id}/cancel', 'post']] as [$path, $method]) {
        expect($names($paths[$path][$method]))->toContain('Idempotency-Key')->and($paths[$path][$method]['responses'])->toHaveKey('400');
    }
    foreach ([['/walk-ins/{id}/arrive', 'post'], ['/walk-ins/queues/{id}/pause', 'post'], ['/walk-ins/queues/{id}/open', 'post'], ['/walk-ins/{id}', 'get']] as [$path, $method]) {
        expect($paths[$path][$method]['responses'])->not->toHaveKey('422');
    }
    expect($names($paths['/walk-ins/search']['post']))->not->toContain('Idempotency-Key')->and($paths['/clinics/{clinicId}/doctor-assignments/{assignmentId}/walkin-policy']['get']['responses'])->toHaveKeys(['403', '404']);
});

it('keeps the registration request free of every field the server decides', function () {
    $properties = array_keys($this->doc['components']['schemas']['RegisterWalkInRequest']['properties']);

    expect($properties)->toEqualCanonicalizing(['doctor_service_id', 'patient_profile_id', 'guest', 'payer_type', 'hmo_card_name_text', 'priority_level', 'priority_reason_code', 'priority_note', 'privacy_notice_version', 'capacity_override', 'acknowledge_late_risk'])
        ->and($this->doc['components']['schemas']['RegisterWalkInRequest']['properties']['priority_level']['enum'] ?? ['NORMAL', 'PRIORITY', 'URGENT'])->toBe(['NORMAL', 'PRIORITY', 'URGENT']);
});

it('answers every new refusal with a 409 and the envelope', function () {
    foreach ([ErrorCode::QueueNotStarted, ErrorCode::RegistrationNotAllowed, ErrorCode::CapacityOverrideRequired, ErrorCode::EntryAlreadyLive, ErrorCode::DailyLimitReached, ErrorCode::LateRiskAckRequired, ErrorCode::QueueClosed, ErrorCode::QueueFull] as $code) {
        expect($code->httpStatus())->toBe(409)->and($code->defaultMessage())->not->toBeEmpty();
    }
    expect(ErrorCode::IdempotencyKeyReused->httpStatus())->toBe(422)->and(ErrorCode::RateLimited->httpStatus())->toBe(429);
});

it('carries exactly the keys each audience may see in an entry', function () {
    doctor_in($this->w);
    $staff = staff_of($this->w);
    [, $profile, $headers] = remote_patient();
    $guest = register_walkin($staff, guest_body($this->svc, extra: ['payer_type' => 'HMO', 'hmo_card_name_text' => 'Maxicare', 'priority_level' => 'PRIORITY', 'priority_reason_code' => 'OTHER', 'priority_note' => 'x']))->assertCreated();
    $patient = register_walkin($headers, patient_body($this->svc, $profile))->assertCreated();

    $common = ['id', 'label', 'queue_number', 'status', 'queue_id', 'local_date', 'timezone', 'clinic_location_id', 'location_name', 'doctor_profile_id', 'doctor_name', 'doctor_service_id', 'service_name', 'fee', 'payer_type', 'registered_at', 'arrived_at'];
    expect(array_keys($patient->json('data')))->toEqualCanonicalizing([...$common, 'estimate'])
        ->and(array_keys($patient->json('data.estimate')))->toEqualCanonicalizing(['position', 'min_minutes', 'max_minutes', 'is_estimate', 'may_not_be_served_today', 'wait_status'])
        ->and(array_keys($guest->json('data')))->toEqualCanonicalizing([...$common, 'registration_channel', 'is_guest', 'name', 'priority_level', 'priority_reason_code', 'hmo_claim', 'estimate'])
        ->and(array_keys($guest->json('data.estimate')))->toEqualCanonicalizing(['position', 'min_minutes', 'max_minutes', 'is_estimate', 'may_not_be_served_today', 'wait_status', 'basis']);

    $detail = wi_json("/api/v1/walk-ins/{$guest->json('data.id')}", $staff)->assertOk()->json('data');
    $restricted = wi_json("/api/v1/walk-ins/{$guest->json('data.id')}", session_for(account(Role::SupportAgent)))->assertOk()->json('data');
    expect(array_keys($detail))->toEqualCanonicalizing([...$common, 'registration_channel', 'is_guest', 'name', 'priority_level', 'priority_reason_code', 'hmo_claim', 'estimate', 'guest_phone', 'priority_note'])
        ->and(array_keys($restricted))->toEqualCanonicalizing([...$common, 'priority_level', 'registration_channel', 'is_guest']);
});

it('puts a named limiter on every walk-in route and Idempotency-Key on the writes the plan names', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/walk-ins') || str_ends_with($r->uri(), '/walkin-policy'));
    $limiter = fn ($r) => collect($r->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:walkin-'));
    $key = fn ($r) => in_array('idempotent', $r->gatherMiddleware(), true);

    expect($routes)->toHaveCount(11);
    foreach ($routes as $route) {
        expect($limiter($route))->not->toBeNull($route->uri())->and($route->gatherMiddleware())->toContain('auth:sanctum');
    }
    $byAction = $routes->mapWithKeys(fn ($r) => [implode('|', $r->methods()).' '.str_replace('api/v1/', '', $r->uri()) => $r]);
    expect($limiter($byAction['POST walk-ins']))->toBe('throttle:walkin-register')->and($limiter($byAction['POST walk-ins/search']))->toBe('throttle:walkin-search')
        ->and($limiter($byAction['GET|HEAD walk-ins/queue']))->toBe('throttle:walkin-read');
    foreach (['POST walk-ins', 'POST walk-ins/{id}/arrive', 'POST walk-ins/{id}/cancel'] as $needsKey) {
        expect($key($byAction[$needsKey]))->toBeTrue($needsKey);
    }
    foreach (['POST walk-ins/queues/{id}/pause', 'POST walk-ins/queues/{id}/open', 'GET|HEAD walk-ins/{id}', 'POST walk-ins/search'] as $noKey) {
        expect($key($byAction[$noKey]))->toBeFalse($noKey);
    }
});

it('reads the limiter values from config', function () {
    expect(config('walkins.limits'))->toMatchArray(['register_patient_per_minute' => 10, 'register_staff_per_minute' => 60, 'search_per_minute' => 30, 'read_per_minute' => 120])
        ->and(config('walkins.guest_pii_retention_days'))->toBe(30)->and(config('walkins.estimation.appointment_grace_minutes'))->toBe(15)
        ->and(config('walkins.remote'))->toBe(['max_live_entries_per_patient' => 2, 'max_patients_per_account_per_day' => 5]);
});
