<?php

use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Rbac\Enums\Role;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 5.5a: patient-app (remote) walk-in registration. Off by default per doctor and location (A1), needs the account's verified
 * mobile number, the doctor in at the location, and respects the daily limits (A7), the late-risk acknowledgement (E1) and the
 * patient estimate view (E3). Dependants need guardian authorization.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    [$this->user, $this->profile, $this->headers] = remote_patient();
});

it('is off until the clinic turns it on (A1)', function () {
    $w = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    doctor_in($w);

    register_walkin($this->headers, patient_body($w['service'], $this->profile))->assertStatus(409)->assertJsonPath('code', 'REGISTRATION_NOT_ALLOWED')->assertJsonPath('reason_code', 'remote_registration_off');
    expect(DB::table('walkin_queues')->count())->toBe(0);
});

it('needs the calling account\'s verified mobile number (decision 246)', function () {
    doctor_in($this->w);
    [, $profile, $headers] = remote_patient(verified: false);

    register_walkin($headers, patient_body($this->svc, $profile))->assertStatus(409)->assertJsonPath('code', 'REGISTRATION_NOT_ALLOWED')->assertJsonPath('reason_code', 'mobile_not_verified');
    // A mobile number that is set but not verified does not count either.
    DB::table('users')->where('id', PatientProfile::findOrFail($profile)->user_id)->update(['mobile_e164' => '+639170000001']);
    register_walkin($headers, patient_body($this->svc, $profile))->assertStatus(409)->assertJsonPath('reason_code', 'mobile_not_verified');
});

it('refuses before the doctor checks in: the queue has not started (B2), and no queue is created', function () {
    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertStatus(409)->assertJsonPath('code', 'QUEUE_NOT_STARTED');

    expect(DB::table('walkin_queues')->count())->toBe(0);
});

it('registers a patient once the doctor is in: REGISTERED, not arrived, patient estimate view, generic in-app notice', function () {
    doctor_in($this->w);

    $r = register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated();

    $r->assertJsonPath('data.status', 'REGISTERED')->assertJsonPath('data.label', 'A-01')->assertJsonPath('data.arrived_at', null)->assertJsonPath('data.fee.amount', 65000)
        ->assertJsonPath('data.estimate.is_estimate', true)->assertJsonPath('data.estimate.position', 1)->assertJsonPath('data.estimate.wait_status', 'ESTIMATED');
    expect($r->json('data.estimate'))->toHaveKeys(['position', 'min_minutes', 'max_minutes', 'is_estimate', 'may_not_be_served_today', 'wait_status'])->not->toHaveKey('basis')
        ->and($r->json('data'))->not->toHaveKeys(['name', 'guest_phone', 'registration_channel', 'priority_level', 'hmo_claim']);

    $entry = DB::table('walkin_queue_entries')->sole();
    expect($entry->registration_channel)->toBe('PATIENT_APP')->and($entry->arrived_at)->toBeNull()->and($entry->patient_profile_id)->toBe($this->profile)->and($entry->registered_by_user_id)->toBe($this->user->id)
        ->and($entry->estimated_call_at_min)->not->toBeNull();

    $notice = DB::table('notifications')->where('type', 'walkins.registered')->sole();
    $data = json_decode($notice->data, true);
    expect($notice->notifiable_id)->toBe($this->user->id)->and($data['title'])->toBe('You joined a walk-in queue')
        ->and($data['body'])->toBe('This is a queue number, not an appointment. Open the app to see your place and estimated wait.')
        ->and(array_keys($data))->toEqualCanonicalizing(['entry_id', 'title', 'body'])
        ->and(json_encode($data))->not->toContain('Santos')->not->toContain('A-01');
});

it('follows the presence table for the patient app (decision 179)', function () {
    doctor_in($this->w);
    $row = fn () => DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->w['doctor']->id);

    // ON_BREAK: allowed, and the break end feeds the estimate.
    presence_post('break/start', $this->w['doctorHeaders'], ['expected_minutes' => 15])->assertOk();
    $onBreak = register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated();
    expect($onBreak->json('data.estimate.min_minutes'))->toBeGreaterThanOrEqual(14);
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/walk-ins/{$onBreak->json('data.id')}/cancel", ['reason_code' => 'PATIENT_REQUEST'], [...$this->headers, 'Idempotency-Key' => (string) Str::uuid7()])->assertOk();

    // TEMPORARILY_UNAVAILABLE: refused.
    presence_post('break/end', $this->w['doctorHeaders'])->assertOk();
    presence_post('unavailable', $this->w['doctorHeaders'], ['reason' => 'PHONE_CALL'])->assertOk();
    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertStatus(409)->assertJsonPath('reason_code', 'doctor_unavailable');

    // IN again: allowed.
    presence_post('available', $this->w['doctorHeaders'])->assertOk();
    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated();

    // OUT today: refused. IN at another location: the queue has not started here.
    $row()->update(['current_status' => 'OUT', 'checked_out_at' => $this->clock->now()->addMinute(), 'checkout_reason' => 'END_OF_SHIFT', 'checked_out_method' => 'SYSTEM']);
    [, $profile2, $headers2] = remote_patient();
    register_walkin($headers2, patient_body($this->svc, $profile2))->assertStatus(409)->assertJsonPath('reason_code', 'doctor_checked_out');
    $elsewhere = wi_second_location($this->w);
    $row()->update(['clinic_location_id' => $elsewhere, 'current_status' => 'IN', 'checked_out_at' => null, 'checkout_reason' => null, 'checked_out_by' => null, 'checked_out_method' => null]);
    register_walkin($headers2, patient_body($this->svc, $profile2))->assertStatus(409)->assertJsonPath('code', 'QUEUE_NOT_STARTED');
});

it('answers ENTRY_ALREADY_LIVE with the existing entry id, and allows a new entry after a cancel', function () {
    doctor_in($this->w);
    $first = register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated()->json('data.id');

    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertStatus(409)->assertJsonPath('code', 'ENTRY_ALREADY_LIVE')->assertJsonPath('errors.entry_id.0', $first);
    expect(DB::table('walkin_queue_entries')->count())->toBe(1)->and(DB::table('walkin_queues')->value('last_number'))->toBe(1);

    app('auth')->forgetGuards();
    $this->postJson("/api/v1/walk-ins/{$first}/cancel", ['reason_code' => 'PATIENT_REQUEST'], [...$this->headers, 'Idempotency-Key' => (string) Str::uuid7()])->assertOk();
    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated()->assertJsonPath('data.label', 'A-02');
});

it('limits live entries per patient to two a day, counting SKIPPED, and frees a place when one is cancelled (A7)', function () {
    doctor_in($this->w);
    wi_seed_entry($this->w, $this->profile, 'WAITING', $this->user->id);
    wi_seed_entry($this->w, $this->profile, 'SKIPPED', $this->user->id);

    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertStatus(409)->assertJsonPath('code', 'DAILY_LIMIT_REACHED')->assertJsonPath('reason_code', 'live_entries_per_patient')->assertJsonPath('errors.limit.0', '2');

    DB::table('walkin_queue_entries')->where('status', 'SKIPPED')->update(['status' => 'CANCELLED']);
    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated();
    expect(DB::table('walkin_queue_entries')->where('patient_profile_id', $this->profile)->whereIn('status', ['REGISTERED', 'WAITING'])->count())->toBe(2);
});

it('limits an account to five distinct patients a day from the app; NO_SHOW never counts (A7)', function () {
    doctor_in($this->w);
    $dependant = wi_dependent($this->headers);
    $ids = collect(range(1, 4))->map(fn () => PatientProfile::factory()->create()->id);
    foreach ($ids as $id) {
        wi_seed_entry($this->w, $id, 'CANCELLED', $this->user->id);
    }
    wi_seed_entry($this->w, $this->profile, 'NO_SHOW', $this->user->id); // five patients, but a no-show is not counted: four distinct so far

    register_walkin($this->headers, patient_body($this->svc, $dependant))->assertCreated();

    // Now five distinct patients (four seeded, the dependant). A sixth, the guardian herself, is refused; the dependant again is not a new patient.
    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertStatus(409)->assertJsonPath('code', 'DAILY_LIMIT_REACHED')->assertJsonPath('reason_code', 'patients_per_account_per_day')->assertJsonPath('errors.limit.0', '5');
    register_walkin($this->headers, patient_body($this->svc, $dependant))->assertStatus(409)->assertJsonPath('code', 'ENTRY_ALREADY_LIVE');
});

it('does not limit staff registrations at all', function () {
    config(['walkins.remote.max_live_entries_per_patient' => 1, 'walkins.remote.max_patients_per_account_per_day' => 1]);
    $staff = staff_of($this->w);
    foreach (range(1, 3) as $i) {
        register_walkin($staff, guest_body($this->svc, "Guest Number{$i}", ['guest' => ['name' => "Guest Number{$i}"]]))->assertCreated();
    }
});

it('registers an authorized dependant, and refuses anyone else\'s dependant or profile (403)', function () {
    doctor_in($this->w);
    $dependant = wi_dependent($this->headers);
    [, $otherProfile, $otherHeaders] = remote_patient();

    register_walkin($this->headers, patient_body($this->svc, $dependant))->assertCreated()->assertJsonPath('data.status', 'REGISTERED');
    register_walkin($otherHeaders, patient_body($this->svc, $dependant))->assertForbidden();
    register_walkin($otherHeaders, patient_body($this->svc, $this->profile))->assertForbidden();
    register_walkin($this->headers, patient_body($this->svc, $otherProfile))->assertForbidden();
    register_walkin($this->headers, patient_body($this->svc, (string) Str::uuid7()))->assertForbidden();
    expect(DB::table('walkin_queue_entries')->where('registered_by_user_id', '<>', $this->user->id)->count())->toBe(0);
});

it('refuses a revoked guardian', function () {
    doctor_in($this->w);
    $dependant = wi_dependent($this->headers);
    DB::table('dependents')->where('dependent_patient_profile_id', $dependant)->update(['authorization_status' => 'REVOKED', 'revoked_at' => now()]);

    register_walkin($this->headers, patient_body($this->svc, $dependant))->assertForbidden();
});

it('refuses fields only the clinic may set on the patient path (422)', function () {
    doctor_in($this->w);

    foreach (['priority_level' => 'URGENT', 'priority_reason_code' => 'OTHER', 'priority_note' => 'x', 'capacity_override' => true, 'privacy_notice_version' => 'v1', 'hmo_membership_id' => (string) Str::uuid7(), 'registration_channel' => 'STAFF_CONSOLE'] as $field => $value) {
        register_walkin($this->headers, patient_body($this->svc, $this->profile, [$field => $value]))->assertStatus(422)->assertJsonValidationErrorFor($field, 'errors');
    }
    expect(DB::table('walkin_queue_entries')->count())->toBe(0);
});

it('takes an HMO name for an HMO payer and stores no coverage', function () {
    doctor_in($this->w);

    register_walkin($this->headers, patient_body($this->svc, $this->profile, ['payer_type' => 'HMO', 'hmo_card_name_text' => 'Maxicare']))->assertCreated()->assertJsonPath('data.payer_type', 'HMO');
    expect(DB::table('walkin_queue_entries')->sole()->hmo_membership_id)->toBeNull();
});

it('refuses a full queue with QUEUE_FULL and reveals no counts to the patient (B9, E2)', function () {
    doctor_in($this->w);
    DB::table('doctor_schedule_shifts')->update(['walkin_capacity' => 1]);
    register_walkin(staff_of($this->w), guest_body($this->svc))->assertCreated();

    $r = register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertStatus(409)->assertJsonPath('code', 'QUEUE_FULL');
    expect($r->getContent())->not->toContain('capacity')->not->toContain('current');
});

it('makes the patient acknowledge a range that reaches closing time before joining (E1, enforced on the server)', function () {
    doctor_in($this->w);
    walkin_policy($this->w['manager'], $this->w['clinic'], $this->w['assignment'], ['cutoff_minutes_before_close' => 0])->assertOk();
    $this->clock->at = next_monday_at('11:40');
    $staff = staff_of($this->w);
    foreach (['Guest Aaa', 'Guest Bbb', 'Guest Ccc'] as $name) {
        register_walkin($staff, guest_body($this->svc, $name, ['guest' => ['name' => $name]]))->assertCreated();
    }

    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertStatus(409)->assertJsonPath('code', 'LATE_RISK_ACK_REQUIRED');
    expect(DB::table('walkin_queue_entries')->where('patient_profile_id', $this->profile)->count())->toBe(0);

    register_walkin($this->headers, patient_body($this->svc, $this->profile, ['acknowledge_late_risk' => true]))->assertCreated()->assertJsonPath('data.estimate.may_not_be_served_today', true);
});

it('replays a patient registration with the same key, and the entry guard answers after the key row is gone', function () {
    doctor_in($this->w);
    $key = (string) Str::uuid7();
    $first = register_walkin($this->headers, patient_body($this->svc, $this->profile), $key)->assertCreated();

    expect(register_walkin($this->headers, patient_body($this->svc, $this->profile), $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->json('data.id'))->toBe($first->json('data.id'));
    DB::table('idempotency_keys')->delete();
    $guard = register_walkin($this->headers, patient_body($this->svc, $this->profile), $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect($guard->json('data.id'))->toBe($first->json('data.id'))->and(DB::table('walkin_queue_entries')->count())->toBe(1)
        ->and($guard->json('data.estimate'))->toHaveKey('wait_status');

    DB::table('idempotency_keys')->delete();
    register_walkin($this->headers, patient_body($this->svc, wi_dependent($this->headers)), $key)->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
});

it('throttles registration per path: the patient bucket is small, the staff bucket separate (decision 156)', function () {
    doctor_in($this->w);
    config(['walkins.limits.register_patient_per_minute' => 2, 'walkins.limits.register_staff_per_minute' => 5]);

    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertCreated();
    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertStatus(409);
    register_walkin($this->headers, patient_body($this->svc, $this->profile))->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');

    $staff = staff_of($this->w);
    foreach (range(1, 5) as $i) {
        register_walkin($staff, guest_body($this->svc, "Guest Number{$i}", ['guest' => ['name' => "Guest Number{$i}"]]))->assertCreated();
    }
    register_walkin($staff, guest_body($this->svc, 'Guest Overflow', ['guest' => ['name' => 'Guest Overflow']]))->assertStatus(429);
});

it('refuses roles without patient rights on the patient path', function () {
    doctor_in($this->w);
    foreach ([Role::FinanceOfficer, Role::SupportAgent, Role::Moderator] as $role) {
        register_walkin(session_for(account($role)), patient_body($this->svc, $this->profile))->assertForbidden();
    }
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/walk-ins', patient_body($this->svc, $this->profile), ['Idempotency-Key' => (string) Str::uuid7()])->assertUnauthorized();
});
