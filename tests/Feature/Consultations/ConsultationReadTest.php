<?php

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Rbac\Enums\Role;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * Increment 5.7-2: reads of consultations (brief §4 5.7-2, rbac-permissions §8). The full view for the doctor and the patient, the
 * restricted whitelist for clinic staff and global readers (audited), 403 without consultations.view, 404 without standing, and the
 * consultation id on the staff entry view.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    $this->staff = staff_of($this->w);
    doctor_in($this->w);
    [$this->user, $this->profile, $this->patient] = remote_patient();
    consultation_clock($this->clock);
});

/** A walk-in patient registered, arrived and called: [entry id, consultation id]. Local copy: this file runs alone. */
function cr_called(?array $who = null): array
{
    [$headers, $profile] = $who ?? [test()->patient, test()->profile];
    fresh_session();
    $entry = register_walkin($headers, patient_body(test()->svc, $profile))->assertCreated()->json('data.id');
    fresh_session();
    wi_post("{$entry}/arrive", test()->staff)->assertOk();
    fresh_session();
    wi_call_next(wi_queue_id(), test()->staff)->assertOk()->assertJsonPath('data.id', $entry);

    return [$entry, (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id')];
}

function cr_post(string $path, array $headers): TestResponse
{
    return act_post("/api/v1/consultations/{$path}", $headers);
}

/** A walk-in patient called and started: [entry id, consultation id]. */
function cr_started(): array
{
    [$entry, $consultation] = cr_called();
    cr_post("{$consultation}/start", test()->w['doctorHeaders'])->assertOk();

    return [$entry, $consultation];
}

/** The restricted whitelist, exactly (the contract: nothing else may leak to a non-clinical reader). */
const CR_RESTRICTED_KEYS = ['id', 'status', 'consultation_type', 'clinic_location_id', 'doctor_profile_id', 'started_at', 'ended_at', 'duration_seconds', 'walkin_queue_entry_id', 'appointment_id'];

it('gives the doctor the full view, with the participant roles and no names', function () {
    [, $consultation] = cr_started();

    $data = wi_json("/api/v1/consultations/{$consultation}", $this->w['doctorHeaders'])->assertOk()->json('data');

    expect(array_keys($data))->toEqualCanonicalizing([...CR_RESTRICTED_KEYS, 'patient_profile_id', 'participant_roles'])
        ->and($data['patient_profile_id'])->not->toBeNull()
        ->and($data['participant_roles'])->toBe(['DOCTOR', 'PATIENT']);
});

it('gives the patient their own consultation in the full view', function () {
    [, $consultation] = cr_started();

    wi_json("/api/v1/consultations/{$consultation}", $this->patient)->assertOk()->assertJsonPath('data.patient_profile_id', $this->profile);
});

it('gives clinic staff the restricted view only, with exactly the whitelisted keys', function () {
    [, $consultation] = cr_started();

    $data = wi_json("/api/v1/consultations/{$consultation}", $this->staff)->assertOk()->json('data');

    expect(array_keys($data))->toEqualCanonicalizing(CR_RESTRICTED_KEYS)
        ->and($data['doctor_profile_id'])->toBe($this->w['doctor']->id);
});

it('gives a global reader the restricted view and audits the read', function () {
    [, $consultation] = cr_started();
    $admin = session_for(account(Role::Admin));

    $response = wi_json("/api/v1/consultations/{$consultation}", $admin)->assertOk();

    expect(array_keys($response->json('data')))->toEqualCanonicalizing(CR_RESTRICTED_KEYS)
        ->and(DB::table('audit_logs')->where('action', 'consultations.viewed')->where('subject_id', $consultation)->count())->toBe(1);
});

it('audits every list read by a global reader and returns restricted rows', function () {
    cr_started();
    $support = session_for(account(Role::SupportAgent));

    $response = wi_json('/api/v1/consultations', $support)->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and(array_keys($response->json('data.0')))->toEqualCanonicalizing(CR_RESTRICTED_KEYS)
        ->and($response->json('meta.per_page'))->toBe(20)
        ->and($response->json('meta.next_cursor'))->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'consultations.listed')->count())->toBe(1);
});

it('does not audit a list read by clinic staff, who see the restricted view by standing', function () {
    cr_started();

    wi_json('/api/v1/consultations', $this->staff)->assertOk()->assertJsonCount(1, 'data');

    expect(DB::table('audit_logs')->where('action', 'consultations.listed')->count())->toBe(0);
});

it('lists the doctor their own consultations, filtered by status', function () {
    cr_started();

    wi_json('/api/v1/consultations?status=IN_PROGRESS', $this->w['doctorHeaders'])->assertOk()->assertJsonCount(1, 'data');
    wi_json('/api/v1/consultations?status=SCHEDULED', $this->w['doctorHeaders'])->assertOk()->assertJsonCount(0, 'data');
});

it('refuses a bad filter with a validation error', function () {
    wi_json('/api/v1/consultations?status=NOPE', $this->w['doctorHeaders'])->assertStatus(422);
});

it('refuses the list and the detail to finance with 403, and to a patient with no standing with 404', function () {
    [, $consultation] = cr_started();
    $finance = session_for(account(Role::FinanceOfficer));
    [$otherUser, , $otherHeaders] = remote_patient();

    wi_json('/api/v1/consultations', $finance)->assertStatus(403);
    wi_json("/api/v1/consultations/{$consultation}", $finance)->assertStatus(403);
    wi_json("/api/v1/consultations/{$consultation}", $otherHeaders)->assertStatus(404);
    expect($otherUser)->not->toBeNull();
});

it('shows the consultation id on the staff entry view while the entry is live, and never on the patient view', function () {
    [$entry, $consultation] = cr_called();

    wi_json("/api/v1/walk-ins/{$entry}", $this->staff)->assertOk()->assertJsonPath('data.consultation_id', $consultation);
    wi_json("/api/v1/walk-ins/{$entry}", $this->patient)->assertOk()->assertJsonMissingPath('data.consultation_id');

    cr_post("{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    cr_post("{$consultation}/end", $this->w['doctorHeaders'])->assertOk();

    wi_json("/api/v1/walk-ins/{$entry}", $this->staff)->assertOk()->assertJsonPath('data.consultation_id', null);
});

it('gives the doctor the walk-in entry id in the full view', function () {
    [$entry, $consultation] = cr_started();

    wi_json("/api/v1/consultations/{$consultation}", $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.walkin_queue_entry_id', $entry);
});

/** The full view, exactly: the restricted whitelist plus the patient id and the participant roles. */
const CR_FULL_KEYS = [...CR_RESTRICTED_KEYS, 'patient_profile_id', 'participant_roles'];

/**
 * A walk-in for a dependant of an authorised guardian (the guardian registers it from the app, staff call it), then the link is set to
 * $status. Returns [consultation id, dependant patient profile id, guardian headers].
 */
function cr_dependant(string $status): array
{
    [, , $guardianHeaders] = remote_patient();
    $dependant = wi_dependent($guardianHeaders);
    DB::table('dependents')->where('dependent_patient_profile_id', $dependant)->update(['authorization_status' => 'AUTHORIZED', 'authorized_at' => now()]);
    [, $consultation] = cr_called([$guardianHeaders, $dependant]);
    DB::table('dependents')->where('dependent_patient_profile_id', $dependant)->update([
        'authorization_status' => $status, 'authorized_at' => $status === 'AUTHORIZED' ? now() : null, 'revoked_at' => $status === 'REVOKED' ? now() : null,
    ]);

    return [$consultation, $dependant, $guardianHeaders];
}

it('gives an authorised guardian the full view of their dependant\'s consultation, on show and on the list', function () {
    [$consultation, $dependant, $guardian] = cr_dependant('AUTHORIZED');

    $show = wi_json("/api/v1/consultations/{$consultation}", $guardian)->assertOk();
    expect(array_keys($show->json('data')))->toEqualCanonicalizing(CR_FULL_KEYS)
        ->and($show->json('data.patient_profile_id'))->toBe($dependant);

    $list = wi_json('/api/v1/consultations', $guardian)->assertOk();
    expect($list->json('data'))->toHaveCount(1)
        ->and(array_keys($list->json('data.0')))->toEqualCanonicalizing(CR_FULL_KEYS)
        ->and($list->json('data.0.patient_profile_id'))->toBe($dependant);
});

it('hides a dependant\'s consultation from a guardian whose link is not authorised: 404 on show, no row on the list', function (string $status) {
    [$consultation, , $guardian] = cr_dependant($status);

    wi_json("/api/v1/consultations/{$consultation}", $guardian)->assertStatus(404);
    wi_json('/api/v1/consultations', $guardian)->assertOk()->assertJsonCount(0, 'data');
})->with(['PENDING', 'REVOKED']);

it('keeps patient A\'s list to A\'s own consultation, and hides B\'s on show', function () {
    [, $consultationA] = cr_started();
    cr_post("{$consultationA}/end", $this->w['doctorHeaders'])->assertOk();
    [, $profileB, $headersB] = remote_patient();
    [, $consultationB] = cr_called([$headersB, $profileB]);
    cr_post("{$consultationB}/start", $this->w['doctorHeaders'])->assertOk();

    $list = wi_json('/api/v1/consultations', $this->patient)->assertOk();
    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('data.0.id'))->toBe($consultationA);
    wi_json("/api/v1/consultations/{$consultationB}", $this->patient)->assertStatus(404);
});

it('shows another doctor none of this doctor\'s consultations: 0 rows on the list, 404 on show', function () {
    [, $consultation] = cr_started();
    $other = wi_second_doctor($this->w);

    wi_json('/api/v1/consultations', $other['headers'])->assertOk()->assertJsonCount(0, 'data');
    wi_json("/api/v1/consultations/{$consultation}", $other['headers'])->assertStatus(404);
});

it('shows staff of another clinic none of the consultation: 0 rows on the list, 404 on show', function () {
    [, $consultation] = cr_started();
    [$otherClinic] = clinic_with_manager('Other Clinic');
    [, $otherStaff] = clinic_member($otherClinic, Role::ClinicStaff);

    wi_json('/api/v1/consultations', $otherStaff)->assertOk()->assertJsonCount(0, 'data');
    wi_json("/api/v1/consultations/{$consultation}", $otherStaff)->assertStatus(404);
});

it('scopes location-restricted staff to their own location: 0 rows and 404 at another branch, the restricted view at theirs', function () {
    [, $consultation] = cr_started();
    $second = wi_second_location($this->w);
    $elsewhere = staff_of($this->w, Role::ClinicStaff, $second);
    $here = staff_of($this->w, Role::ClinicStaff, $this->w['location']);

    wi_json('/api/v1/consultations', $elsewhere)->assertOk()->assertJsonCount(0, 'data');
    wi_json("/api/v1/consultations/{$consultation}", $elsewhere)->assertStatus(404);

    wi_json('/api/v1/consultations', $here)->assertOk()->assertJsonCount(1, 'data');
    expect(array_keys(wi_json("/api/v1/consultations/{$consultation}", $here)->assertOk()->json('data')))->toEqualCanonicalizing(CR_RESTRICTED_KEYS);
});

it('gives the clinic manager the restricted view of the consultations at their clinic', function () {
    [, $consultation] = cr_started();

    $list = wi_json('/api/v1/consultations', $this->w['manager'])->assertOk();
    expect($list->json('data'))->toHaveCount(1)
        ->and(array_keys($list->json('data.0')))->toEqualCanonicalizing(CR_RESTRICTED_KEYS);
    expect(array_keys(wi_json("/api/v1/consultations/{$consultation}", $this->w['manager'])->assertOk()->json('data')))->toEqualCanonicalizing(CR_RESTRICTED_KEYS);
});

it('gives the medical administrator the restricted view only, and audits each show and list read', function () {
    [, $consultation] = cr_started();
    $medical = session_for(account(Role::MedicalAdmin));

    $show = wi_json("/api/v1/consultations/{$consultation}", $medical)->assertOk();
    expect(array_keys($show->json('data')))->toEqualCanonicalizing(CR_RESTRICTED_KEYS)
        ->and(DB::table('audit_logs')->where('action', 'consultations.viewed')->where('subject_id', $consultation)->count())->toBe(1);

    $list = wi_json('/api/v1/consultations', $medical)->assertOk();
    expect(array_keys($list->json('data.0')))->toEqualCanonicalizing(CR_RESTRICTED_KEYS)
        ->and(DB::table('audit_logs')->where('action', 'consultations.listed')->count())->toBe(1);
});

it('refuses the super administrator with 403 on the list and the detail (owner default: keep denied)', function () {
    [, $consultation] = cr_started();
    $super = session_for(account(Role::SuperAdmin));

    wi_json('/api/v1/consultations', $super)->assertStatus(403);
    wi_json("/api/v1/consultations/{$consultation}", $super)->assertStatus(403);
});

it('gives a patient with no standing an empty list', function () {
    cr_started();
    [, , $stranger] = remote_patient();

    wi_json('/api/v1/consultations', $stranger)->assertOk()->assertJsonCount(0, 'data');
});

it('records the ids of the listed consultations on the global list audit row (L-4)', function () {
    [, $consultation] = cr_started();
    $support = session_for(account(Role::SupportAgent));

    wi_json('/api/v1/consultations', $support)->assertOk();

    $changes = json_decode((string) DB::table('audit_logs')->where('action', 'consultations.listed')->value('changes'), true);
    expect($changes['consultation_ids'])->toBe([$consultation]);
});
