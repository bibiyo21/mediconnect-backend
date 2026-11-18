<?php

use App\Modules\Patients\Models\Dependent;
use App\Modules\Patients\Models\PatientProfile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    seed_roles();
    fake_messages();
});

function dependent_payload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Lia',
        'last_name' => 'Santos',
        'date_of_birth' => '2018-03-10',
        'sex' => 'FEMALE',
        'relationship' => 'CHILD',
        'guardian_attestation' => true,
        'guardian_attestation_version' => config('mediconnect.dependents.guardian_attestation_version'),
    ], $overrides);
}

function with_key(array $headers): array
{
    return [...$headers, 'Idempotency-Key' => (string) Str::uuid7()];
}

function add_dependent(array $headers, array $overrides = []): string
{
    return test()->postJson('/api/v1/patients/me/dependents', dependent_payload($overrides), with_key($headers))->assertCreated()->json('data.id');
}

it('adds a child as a dependent with a recorded guardian attestation', function () {
    $guardian = patient_account();
    $headers = signed_in($guardian);

    $response = $this->postJson('/api/v1/patients/me/dependents', dependent_payload(), with_key($headers))
        ->assertCreated()
        ->assertJsonPath('data.first_name', 'Lia')
        ->assertJsonPath('data.relationship', 'CHILD')
        ->assertJsonPath('data.date_of_birth', '2018-03-10');

    $profile = PatientProfile::findOrFail($response->json('data.id'));
    $link = Dependent::where('dependent_patient_profile_id', $profile->id)->sole();
    $consent = DB::table('consent_records')->where('id', $link->consent_record_id)->sole();

    expect($profile->user_id)->toBeNull()
        ->and($link->authorization_status->value)->toBe('AUTHORIZED')
        ->and($consent->consent_type)->toBe('GUARDIAN_AUTHORIZATION')
        ->and($consent->user_id)->toBe($guardian->id)
        ->and($consent->subject_patient_profile_id)->toBe($profile->id)
        ->and((bool) $consent->granted)->toBeTrue();
});

it('lists, shows and updates only the guardian\'s own dependents', function () {
    $guardian = patient_account();
    $headers = signed_in($guardian);
    $id = add_dependent($headers);
    add_dependent($headers, ['first_name' => 'Ben', 'relationship' => 'PARENT', 'date_of_birth' => '1950-01-01']);

    $this->getJson('/api/v1/patients/me/dependents', $headers)->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/v1/patients/me/dependents/{$id}", $headers)->assertOk()->assertJsonPath('data.first_name', 'Lia');
    $this->patchJson("/api/v1/patients/me/dependents/{$id}", ['first_name' => ' Liana ', 'relationship' => 'LEGAL_WARD'], $headers)
        ->assertOk()->assertJsonPath('data.first_name', 'Liana')->assertJsonPath('data.relationship', 'LEGAL_WARD');
});

it('hides another guardian\'s dependents behind 404 (no IDOR)', function () {
    $alice = patient_account();
    $bob = patient_account();
    $aliceChild = add_dependent(signed_in($alice));
    $this->app['auth']->forgetGuards();
    $bobHeaders = signed_in($bob);
    $this->app['auth']->forgetGuards();

    $this->getJson("/api/v1/patients/me/dependents/{$aliceChild}", $bobHeaders)->assertNotFound();
    $this->app['auth']->forgetGuards();
    $this->patchJson("/api/v1/patients/me/dependents/{$aliceChild}", ['first_name' => 'X'], $bobHeaders)->assertNotFound();
    $this->app['auth']->forgetGuards();
    $this->deleteJson("/api/v1/patients/me/dependents/{$aliceChild}", [], $bobHeaders)->assertNotFound();
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/patients/me/dependents', $bobHeaders)->assertOk()->assertJsonCount(0, 'data');

    expect(PatientProfile::find($aliceChild)->first_name)->toBe('Lia');
});

it('removes a dependent by revoking the link, keeps their record and records the withdrawal', function () {
    $guardian = patient_account();
    $headers = signed_in($guardian);
    $id = add_dependent($headers);

    $this->deleteJson("/api/v1/patients/me/dependents/{$id}", [], $headers)->assertOk();

    $this->getJson("/api/v1/patients/me/dependents/{$id}", $headers)->assertNotFound();
    $this->getJson('/api/v1/patients/me/dependents', $headers)->assertJsonCount(0, 'data');
    expect(PatientProfile::find($id))->not->toBeNull()
        ->and(Dependent::where('dependent_patient_profile_id', $id)->sole()->authorization_status->value)->toBe('REVOKED')
        ->and(DB::table('consent_records')->where('subject_patient_profile_id', $id)->orderBy('id')->pluck('granted')->map(fn ($g) => (bool) $g)->all())->toBe([true, false]);
});

it('requires the attestation, the current attestation version and an Idempotency-Key', function () {
    $headers = signed_in(patient_account());

    $this->postJson('/api/v1/patients/me/dependents', dependent_payload(['guardian_attestation' => false]), with_key($headers))
        ->assertStatus(422)->assertJsonValidationErrors('guardian_attestation', 'errors');
    $this->postJson('/api/v1/patients/me/dependents', dependent_payload(['guardian_attestation_version' => 'v0']), with_key($headers))
        ->assertStatus(422)->assertJsonValidationErrors('guardian_attestation_version', 'errors');
    $this->postJson('/api/v1/patients/me/dependents', dependent_payload(), $headers)->assertStatus(400);

    expect(Dependent::count())->toBe(0);
});

it('replays a retried add instead of creating a duplicate', function () {
    $headers = with_key(signed_in(patient_account()));

    $first = $this->postJson('/api/v1/patients/me/dependents', dependent_payload(), $headers)->assertCreated()->json('data.id');
    $second = $this->postJson('/api/v1/patients/me/dependents', dependent_payload(), $headers)->assertCreated()->json('data.id');

    expect($second)->toBe($first)->and(Dependent::count())->toBe(1);
});

it('validates the dependent\'s details', function (array $override, string $field) {
    $this->postJson('/api/v1/patients/me/dependents', dependent_payload($override), with_key(signed_in(patient_account())))
        ->assertStatus(422)->assertJsonValidationErrors($field, 'errors');
})->with([
    'no first name' => [['first_name' => ''], 'first_name'],
    'future birth date' => [['date_of_birth' => now('Asia/Manila')->addDay()->toDateString()], 'date_of_birth'],
    'no birth date' => [['date_of_birth' => null], 'date_of_birth'],
    'unknown relationship' => [['relationship' => 'NEIGHBOUR'], 'relationship'],
]);

it('limits how many dependents a guardian can add', function () {
    config(['mediconnect.dependents.max_per_guardian' => 2]);
    $headers = signed_in(patient_account());
    add_dependent($headers);
    add_dependent($headers, ['first_name' => 'Ben']);

    $this->postJson('/api/v1/patients/me/dependents', dependent_payload(['first_name' => 'Cy']), with_key($headers))->assertStatus(422);
});

it('lets the guardian reach the dependent\'s profile through the policy, and nobody else', function () {
    $guardian = patient_account();
    $stranger = patient_account();
    $id = add_dependent(signed_in($guardian));
    $profile = PatientProfile::findOrFail($id);

    expect($guardian->can('view', $profile))->toBeTrue()
        ->and($stranger->can('view', $profile))->toBeFalse();

    $this->app['auth']->forgetGuards();
    $this->deleteJson("/api/v1/patients/me/dependents/{$id}", [], signed_in($guardian))->assertOk();
    expect($guardian->fresh()->can('view', $profile))->toBeFalse();
});

it('audits additions, changes and removals without personal values', function () {
    $headers = signed_in(patient_account());
    $id = add_dependent($headers);
    $this->patchJson("/api/v1/patients/me/dependents/{$id}", ['last_name' => 'Reyes'], $headers)->assertOk();
    $this->deleteJson("/api/v1/patients/me/dependents/{$id}", [], $headers)->assertOk();

    $rows = DB::table('audit_logs')->where('subject_id', $id)->orderBy('id')->get();
    expect($rows->pluck('action')->all())->toBe(['dependent.added', 'dependent.updated', 'dependent.removed'])
        ->and($rows->pluck('changes')->implode(' '))->not->toContain('Reyes')->not->toContain('Lia');
});

it('enforces the link rules in the database', function () {
    $profile = PatientProfile::factory()->create(['user_id' => null]);
    $other = PatientProfile::factory()->create(['user_id' => null]);
    // Each insert runs in its own savepoint so an expected failure does not abort the test transaction.
    $insert = fn (array $row) => fn () => DB::transaction(fn () => DB::table('dependents')->insert([
        'id' => (string) Str::uuid7(), 'relationship' => 'CHILD', 'created_at' => now(), 'updated_at' => now(), ...$row,
    ]));

    expect($insert(['guardian_patient_profile_id' => $profile->id, 'dependent_patient_profile_id' => $profile->id, 'authorization_status' => 'PENDING']))
        ->toThrow(QueryException::class); // not one's own dependent
    expect($insert(['guardian_patient_profile_id' => $profile->id, 'dependent_patient_profile_id' => $other->id, 'authorization_status' => 'AUTHORIZED']))
        ->toThrow(QueryException::class); // AUTHORIZED needs authorized_at and a consent record
    expect($insert(['guardian_patient_profile_id' => $profile->id, 'dependent_patient_profile_id' => $other->id, 'authorization_status' => 'REVOKED']))
        ->toThrow(QueryException::class); // REVOKED needs revoked_at
});
