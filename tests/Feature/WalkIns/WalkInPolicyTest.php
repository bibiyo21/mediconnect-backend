<?php

use App\Modules\Rbac\Enums\Role;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Increment 5.5a: a doctor's walk-in settings at a location, GET and PATCH
 * /clinics/{clinicId}/doctor-assignments/{assignmentId}/walkin-policy (clinics.update at the location).
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world();
    $this->url = "/api/v1/clinics/{$this->w['clinic']}/doctor-assignments/{$this->w['assignment']}/walkin-policy";
});

function wi_get(string $url, array $headers): TestResponse
{
    app('auth')->forgetGuards();

    return test()->getJson($url, $headers);
}

function wi_patch(string $url, array $headers, array $body): TestResponse
{
    app('auth')->forgetGuards();

    return test()->patchJson($url, $body, $headers);
}

it('shows the defaults and the capacity today\'s schedule derives', function () {
    wi_get($this->url, $this->w['manager'])->assertOk()
        ->assertJsonPath('data.allow_patient_app_registration', false)->assertJsonPath('data.cutoff_minutes_before_close', 30)->assertJsonPath('data.number_prefix', null)
        ->assertJsonPath('data.capacity_mode', 'DERIVED')->assertJsonPath('data.derived_capacity_today', 7)->assertJsonPath('data.timezone', 'Asia/Manila')
        ->assertJsonPath('data.local_date', $this->clock->now()->setTimezone('Asia/Manila')->toDateString())->assertJsonPath('data.assignment_id', $this->w['assignment']);

    DB::table('doctor_schedule_shifts')->update(['walkin_capacity' => 4]);
    wi_get($this->url, $this->w['manager'])->assertJsonPath('data.derived_capacity_today', 4);
    $this->clock->at = next_monday_at('10:00')->addDay();
    wi_get($this->url, $this->w['manager'])->assertJsonPath('data.derived_capacity_today', 0);
});

it('changes only the keys sent, audits them, and does not queue a directory reindex', function () {
    $outbox = DB::table('outbox_events')->count();

    wi_patch($this->url, $this->w['manager'], ['allow_patient_app_registration' => true, 'cutoff_minutes_before_close' => 15])->assertOk()
        ->assertJsonPath('data.allow_patient_app_registration', true)->assertJsonPath('data.cutoff_minutes_before_close', 15)->assertJsonPath('data.capacity_mode', 'DERIVED');
    wi_patch($this->url, $this->w['manager'], ['number_prefix' => 'DR', 'capacity_mode' => 'UNLIMITED'])->assertOk()->assertJsonPath('data.allow_patient_app_registration', true)->assertJsonPath('data.number_prefix', 'DR');
    wi_patch($this->url, $this->w['manager'], ['number_prefix' => null])->assertOk()->assertJsonPath('data.number_prefix', null)->assertJsonPath('data.capacity_mode', 'UNLIMITED');

    wi_get($this->url, $this->w['manager'])->assertJsonPath('data.cutoff_minutes_before_close', 15);
    $audit = DB::table('audit_logs')->where('action', 'walkins.policy_updated')->orderBy('id')->first();
    expect(json_decode($audit->changes, true))->toEqual(['allow_patient_app_registration' => [false, true], 'cutoff_minutes_before_close' => [30, 15]])
        ->and(DB::table('audit_logs')->where('action', 'walkins.policy_updated')->count())->toBe(3)
        ->and(DB::table('outbox_events')->count())->toBe($outbox);
});

it('refuses unknown keys, including the superseded pre-check-in key, and bad values', function () {
    foreach (['allow_pre_checkin_registration' => true, 'foo' => 1] as $key => $value) {
        wi_patch($this->url, $this->w['manager'], [$key => $value])->assertStatus(422)->assertJsonValidationErrorFor($key, 'errors');
    }
    foreach ([['cutoff_minutes_before_close' => 241], ['cutoff_minutes_before_close' => -1], ['number_prefix' => 'abc'], ['number_prefix' => 'TOOLONG'], ['capacity_mode' => 'FOO'], ['allow_patient_app_registration' => 'maybe']] as $body) {
        wi_patch($this->url, $this->w['manager'], $body)->assertStatus(422);
    }
    expect(DB::table('doctor_clinic_assignments')->value('walkin_policy'))->toBeNull();
});

it('ignores a legacy or unreadable stored value instead of opening remote registration', function () {
    DB::table('doctor_clinic_assignments')->update(['walkin_policy' => json_encode(['allow_pre_checkin_registration' => true, 'allow_patient_app_registration' => 'yes', 'cutoff_minutes_before_close' => 9999, 'number_prefix' => 'a1'])]);

    wi_get($this->url, $this->w['manager'])->assertOk()->assertJsonPath('data.allow_patient_app_registration', false)->assertJsonPath('data.cutoff_minutes_before_close', 30)->assertJsonPath('data.number_prefix', null)
        ->assertJsonMissingPath('data.allow_pre_checkin_registration');
});

it('needs clinics.update at the assignment\'s location', function () {
    $second = wi_second_location($this->w);
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);

    foreach ([
        'staff' => staff_of($this->w),
        'manager of another location' => staff_of($this->w, Role::ClinicManager, $second),
        'staff of another location' => staff_of($this->w, Role::ClinicStaff, $second),
        'unrelated manager' => $other['manager'],
        'doctor' => $this->w['doctorHeaders'],
        'finance' => session_for(account(Role::FinanceOfficer)),
        'support' => session_for(account(Role::SupportAgent)),
        'moderator' => session_for(account(Role::Moderator)),
        'patient' => session_for(patient_account()),
    ] as $who => $headers) {
        wi_get($this->url, $headers)->assertForbidden();
        wi_patch($this->url, $headers, ['allow_patient_app_registration' => true])->assertForbidden();
    }
    app('auth')->forgetGuards();
    $this->getJson($this->url)->assertUnauthorized();
    expect(DB::table('doctor_clinic_assignments')->value('walkin_policy'))->toBeNull();

    // A manager restricted to this very location, and an administrator, may.
    wi_patch($this->url, staff_of($this->w, Role::ClinicManager, $this->w['location']), ['allow_patient_app_registration' => true])->assertOk();
    wi_get($this->url, session_for(account(Role::Admin)))->assertOk();
});

it('answers 404 for an assignment that is not in this clinic, and 409 for an ended one', function () {
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    wi_get("/api/v1/clinics/{$this->w['clinic']}/doctor-assignments/{$other['assignment']}/walkin-policy", $this->w['manager'])->assertNotFound();
    wi_get("/api/v1/clinics/{$this->w['clinic']}/doctor-assignments/".Str::uuid7().'/walkin-policy', $this->w['manager'])->assertNotFound();

    DB::table('doctor_clinic_assignments')->where('id', $this->w['assignment'])->update(['status' => 'ENDED', 'effective_until' => now(), 'end_reason' => 'Left the clinic']);
    wi_patch($this->url, $this->w['manager'], ['allow_patient_app_registration' => true])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

it('validates recall_limit (1 to 10) and stores a valid one as an integer', function () {
    foreach ([0, 11, 'x'] as $bad) {
        wi_patch($this->url, $this->w['manager'], ['recall_limit' => $bad])->assertStatus(422);
    }
    wi_patch($this->url, $this->w['manager'], ['recall_limit' => 3])->assertOk()->assertJsonPath('data.recall_limit', 3);

    $stored = json_decode((string) DB::table('doctor_clinic_assignments')->where('id', $this->w['assignment'])->value('walkin_policy'), true);
    expect($stored['recall_limit'])->toBeInt()->toBe(3);
});
