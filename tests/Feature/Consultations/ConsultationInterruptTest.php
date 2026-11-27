<?php

use App\Modules\Consultations\Models\Consultation;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * Increment 5.7-2: interrupt and resume of a walk-in consultation (brief D-G). The entry stays IN_CONSULTATION, the care relationship is
 * untouched, an interrupted consultation is not BUSY, and resuming needs the doctor stored IN with no other consultation in progress.
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
function ci_called(?array $who = null): array
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

function ci_post(string $path, array $headers): TestResponse
{
    return act_post("/api/v1/consultations/{$path}", $headers);
}

/** Starts a called patient and returns [entry id, consultation id]. */
function ci_started(): array
{
    [$entry, $consultation] = ci_called();
    ci_post("{$consultation}/start", test()->w['doctorHeaders'])->assertOk();

    return [$entry, $consultation];
}

function ci_interrupt(string $consultation, array $headers): TestResponse
{
    return act_post("/api/v1/consultations/{$consultation}/interrupt", $headers);
}

function ci_resume(string $consultation, array $headers): TestResponse
{
    return act_post("/api/v1/consultations/{$consultation}/resume", $headers);
}

it('interrupts and resumes a consultation, keeping the entry in consultation and the grant as it was', function () {
    [$entry, $consultation] = ci_started();
    $grant = DB::table('care_relationships')->where('source_id', $entry)->value('access_until');

    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.status', 'INTERRUPTED');
    expect(wi_status($entry))->toBe('IN_CONSULTATION')
        ->and(DB::table('care_relationships')->where('source_id', $entry)->where('status', 'ACTIVE')->value('access_until'))->toBe($grant)
        ->and(DB::table('audit_logs')->where('action', 'consultations.interrupted')->count())->toBe(1);

    ci_resume($consultation, $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
    expect(DB::table('audit_logs')->where('action', 'consultations.resumed')->count())->toBe(1)
        ->and(DB::table('care_relationships')->where('source_id', $entry)->count())->toBe(1);
});

it('answers a repeated interrupt with the current consultation and writes nothing', function () {
    [, $consultation] = ci_started();
    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertOk();

    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.status', 'INTERRUPTED');
    expect(DB::table('audit_logs')->where('action', 'consultations.interrupted')->count())->toBe(1);
});

it('shows the doctor as BUSY while in progress, and as IN (not BUSY) while interrupted', function () {
    [, $consultation] = ci_started();
    fresh_session();
    expect(wi_json('/api/v1/presence/me', $this->w['doctorHeaders'])->assertOk()->json('data.effective_status'))->toBe('BUSY');

    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertOk();
    fresh_session();
    $me = wi_json('/api/v1/presence/me', $this->w['doctorHeaders'])->assertOk()->json('data');
    expect($me['status'])->toBe('IN')->and($me['effective_status'])->toBe('IN');
});

it('refuses an interrupt of a consultation that is not in progress', function () {
    [, $consultation] = ci_called();

    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

it('refuses a resume while another consultation of the doctor is in progress', function () {
    [, $first] = ci_started();
    ci_interrupt($first, $this->w['doctorHeaders'])->assertOk();
    [, $secondProfile, $secondHeaders] = remote_patient();
    [, $second] = ci_called([$secondHeaders, $secondProfile]);
    ci_post("{$second}/start", $this->w['doctorHeaders'])->assertOk();

    ci_resume($first, $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('code', 'ACTIVE_CONSULTATION');
    expect(DB::table('consultations')->where('id', $first)->value('status'))->toBe('INTERRUPTED');
});

it('refuses a resume while the doctor is on break, with no state change', function () {
    [, $consultation] = ci_started();
    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertOk();
    fresh_session();
    presence_post('break/start', $this->w['doctorHeaders'], ['clinic_location_id' => $this->w['location'], 'expected_minutes' => 5])->assertOk();

    ci_resume($consultation, $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('reason_code', 'on_break');
    expect(DB::table('consultations')->where('id', $consultation)->value('status'))->toBe('INTERRUPTED');
});

it('lets an interrupted consultation end, completing the entry', function () {
    [$entry, $consultation] = ci_started();
    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertOk();

    ci_post("{$consultation}/end", $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    expect(wi_status($entry))->toBe('COMPLETED');
});

it('answers a repeated resume with the current consultation and writes nothing', function () {
    [, $consultation] = ci_started();
    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertOk();
    ci_resume($consultation, $this->w['doctorHeaders'])->assertOk();

    ci_resume($consultation, $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
    expect(DB::table('audit_logs')->where('action', 'consultations.resumed')->count())->toBe(1);
});

it('shows the doctor BUSY again once the consultation is resumed (public status follows the move)', function () {
    [, $consultation] = ci_started();
    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertOk();
    fresh_session();
    expect(wi_json('/api/v1/presence/me', $this->w['doctorHeaders'])->assertOk()->json('data.effective_status'))->toBe('IN');

    ci_resume($consultation, $this->w['doctorHeaders'])->assertOk();
    fresh_session();
    expect(wi_json('/api/v1/presence/me', $this->w['doctorHeaders'])->assertOk()->json('data.effective_status'))->toBe('BUSY');
});

it('refuses a resume when the doctor has checked out of the location, with no state change', function () {
    [, $consultation] = ci_started();
    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertOk();
    DB::table('doctor_presence_sessions')->update(['current_status' => 'OUT', 'checked_out_at' => $this->clock->now(), 'checked_in_at' => $this->clock->now()->subHour(), 'checkout_reason' => 'END_OF_SHIFT', 'checked_out_method' => 'SYSTEM']);

    ci_resume($consultation, $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('reason_code', 'checked_out');
    expect(DB::table('consultations')->where('id', $consultation)->value('status'))->toBe('INTERRUPTED');
});

it('refuses a resume when the doctor is no longer verified (M-1 on resume), and leaves the consultation INTERRUPTED', function () {
    [, $consultation] = ci_started();
    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertOk();
    DB::table('doctor_profiles')->where('id', $this->w['doctor']->id)->update(['onboarding_status' => 'REJECTED']);

    ci_resume($consultation, $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('reason_code', 'doctor_not_active');
    expect(DB::table('consultations')->where('id', $consultation)->value('status'))->toBe('INTERRUPTED');
});

it('refuses a resume when the location is no longer active (M-1 on resume)', function () {
    [, $consultation] = ci_started();
    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertOk();
    DB::table('clinic_locations')->where('id', $this->w['location'])->update(['status' => 'INACTIVE']);

    ci_resume($consultation, $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('reason_code', 'location_not_active');
    expect(DB::table('consultations')->where('id', $consultation)->value('status'))->toBe('INTERRUPTED');
});

it('refuses a resume when the doctor has no active assignment at the location (M-1 on resume)', function () {
    [, $consultation] = ci_started();
    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertOk();
    DB::table('doctor_clinic_assignments')->where('id', $this->w['assignment'])->update(['status' => 'ENDED', 'effective_until' => $this->clock->now()]);

    ci_resume($consultation, $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('reason_code', 'no_assignment');
    expect(DB::table('consultations')->where('id', $consultation)->value('status'))->toBe('INTERRUPTED');
});

it('refuses a resume of a consultation whose queue is from an earlier day (not_today), like a start', function () {
    [, $consultation] = ci_started();
    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertOk();
    DB::table('walkin_queues')->update(['local_date' => now()->subDay()->toDateString()]);

    ci_resume($consultation, $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('reason_code', 'not_today');
    expect(DB::table('consultations')->where('id', $consultation)->value('status'))->toBe('INTERRUPTED');
});

it('refuses interrupt and resume to clinic staff with 403, and requires an Idempotency-Key', function () {
    [, $consultation] = ci_started();

    ci_interrupt($consultation, $this->staff)->assertStatus(403);
    ci_interrupt($consultation, $this->w['doctorHeaders'])->assertOk();
    ci_resume($consultation, $this->staff)->assertStatus(403);
    test()->postJson("/api/v1/consultations/{$consultation}/resume", [], $this->w['doctorHeaders'])->assertStatus(400);
    expect(Consultation::query()->whereKey($consultation)->value('status')->value)->toBe('INTERRUPTED');
});
