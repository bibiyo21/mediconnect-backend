<?php

use App\Modules\Rbac\Enums\Role;
use Database\Seeders\SpecialtySeeder;

/*
 * Increment 5.6-3a: the entry view payload guard. The patient's own entry and the restricted detail carry no other patient's name, no
 * staff-only field and no id beyond the entry, its queue, its location, its doctor and its service. Every UUID in the body is checked.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    $this->staff = staff_of($this->w);
    [$this->user, $this->profile, $this->headers] = remote_patient();
    doctor_in($this->w);
    $this->juan = register_walkin($this->staff, guest_body($this->svc, 'Juan Dela Cruz', ['priority_level' => 'NORMAL']))->assertCreated()->json('data.id');
    $this->ana = register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated()->json('data.id');
});

/** Every UUID-shaped string in a response body. */
function ev_uuids(string $body): array
{
    preg_match_all('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', $body, $m);

    return array_values(array_unique($m[0]));
}

it('shows the patient\'s own entry with no other patient\'s name and only the ids the view allows', function () {
    $r = wi_json("/api/v1/walk-ins/{$this->ana}", $this->headers)->assertOk();
    $body = $r->getContent();
    $allowed = [$this->ana, $r->json('data.queue_id'), $r->json('data.clinic_location_id'), $r->json('data.doctor_profile_id'), $r->json('data.doctor_service_id')];

    expect($body)->not->toContain('Juan')->and($body)->not->toContain('Dela Cruz')->and($body)->not->toContain((string) $this->juan)
        ->and(array_diff(ev_uuids($body), $allowed))->toBe([])
        ->and($r->json('data'))->not->toHaveKeys(['called_at', 'recall_count', 'queue_sort_at', 'priority_reason_code', 'priority_note', 'guest_phone', 'name']);
});

it('gives the restricted reader the same guard: no name, no staff-only field, only the allowed ids', function () {
    $r = wi_json("/api/v1/walk-ins/{$this->ana}", session_for(account(Role::SupportAgent)))->assertOk();
    $body = $r->getContent();
    $allowed = [$this->ana, $r->json('data.queue_id'), $r->json('data.clinic_location_id'), $r->json('data.doctor_profile_id'), $r->json('data.doctor_service_id')];

    expect($body)->not->toContain('Juan')->and($body)->not->toContain((string) $this->juan)
        ->and(array_diff(ev_uuids($body), $allowed))->toBe([])
        ->and($r->json('data'))->not->toHaveKeys(['called_at', 'recall_count', 'queue_sort_at', 'name', 'guest_phone', 'priority_note', 'hmo_claim', 'estimate']);
});
