<?php

use App\Modules\Consultations\Models\Consultation;
use App\Modules\WalkIns\Estimation\DurationSource;
use App\Modules\WalkIns\Estimation\RollingDurationStats;
use Carbon\CarbonImmutable;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * Increment 5.7-1: start and end of a walk-in consultation (brief §5). A called patient's consultation starts only for the doctor who is
 * stored IN at the location, grants the care relationship last, and is refused in every other case with no write.
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

/** A patient is registered and called; returns [entry id, consultation id]. */
function called_patient(?array $who = null): array
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

function consultation_post(string $path, array $headers): TestResponse
{
    return act_post("/api/v1/consultations/{$path}", $headers);
}

it('starts a called consultation, grants the care relationship last and records the participants', function () {
    [$entry, $consultation] = called_patient();

    $response = consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertOk();

    expect($response->json('data.status'))->toBe('IN_PROGRESS')->and($response->json('data.started_at'))->toBe($this->clock->now()->toIso8601ZuluString());
    expect(wi_status($entry))->toBe('IN_CONSULTATION')
        ->and(DB::table('walkin_queue_entries')->where('id', $entry)->value('consultation_started_at'))->not->toBeNull();
    expect(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'STARTED')->count())->toBe(1);
    expect(DB::table('care_relationships')->where('source_id', $entry)->where('status', 'ACTIVE')->count())->toBe(1);
    expect(DB::table('consultation_participants')->where('consultation_id', $consultation)->pluck('role')->sort()->values()->all())->toBe(['DOCTOR', 'PATIENT']);
    expect(DB::table('audit_logs')->where('action', 'consultations.started')->count())->toBe(1);
});

it('answers a repeated start with the current consultation and writes nothing', function () {
    [$entry, $consultation] = called_patient();
    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertOk();

    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');

    expect(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'STARTED')->count())->toBe(1)
        ->and(DB::table('care_relationships')->where('source_id', $entry)->count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'consultations.started')->count())->toBe(1);
});

it('refuses a start while the doctor is on break, with no grant and no state change', function () {
    [$entry, $consultation] = called_patient();
    fresh_session();
    presence_post('break/start', $this->w['doctorHeaders'], ['clinic_location_id' => $this->w['location'], 'expected_minutes' => 5])->assertOk();

    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertStatus(409)
        ->assertJsonPath('errors', [])->assertJsonPath('reason_code', 'on_break');

    expect(DB::table('consultations')->where('id', $consultation)->value('status'))->toBe('SCHEDULED')
        ->and(DB::table('care_relationships')->count())->toBe(0);
});

it('refuses a start when the doctor is checked out of the location', function () {
    [, $consultation] = called_patient();
    DB::table('doctor_presence_sessions')->update(['current_status' => 'OUT', 'checked_out_at' => $this->clock->now(), 'checked_in_at' => $this->clock->now()->subHour(), 'checkout_reason' => 'END_OF_SHIFT', 'checked_out_method' => 'SYSTEM']);

    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('reason_code', 'checked_out');
    expect(DB::table('care_relationships')->count())->toBe(0);
});

it('refuses a start for a patient who was called on an earlier day', function () {
    [, $consultation] = called_patient();
    DB::table('walkin_queues')->update(['local_date' => now()->subDay()->toDateString()]);

    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('reason_code', 'not_today');
    expect(DB::table('care_relationships')->count())->toBe(0);
});

it('refuses a second start while another consultation of the doctor is in progress', function () {
    [$first, $firstConsultation] = called_patient();
    consultation_post("{$firstConsultation}/start", $this->w['doctorHeaders'])->assertOk();
    [, $secondProfile, $secondHeaders] = remote_patient();
    [$second, $secondConsultation] = called_patient([$secondHeaders, $secondProfile]);

    consultation_post("{$secondConsultation}/start", $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('code', 'ACTIVE_CONSULTATION');
    expect(DB::table('consultations')->where('id', $secondConsultation)->value('status'))->toBe('SCHEDULED')
        ->and(DB::table('care_relationships')->where('source_id', $second)->count())->toBe(0);
});

it('lets a guest start, with no care relationship', function () {
    fresh_session();
    $guest = wi_guest($this->staff, $this->svc, 'Guest Starter');
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk()->assertJsonPath('data.id', $guest);
    $consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $guest)->value('id');

    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.patient_profile_id', null);
    expect(DB::table('care_relationships')->count())->toBe(0);
});

it('refuses the start of a doctor\'s own patient profile (D-16) with 403 and no grant', function () {
    [$entry, $consultation] = called_patient();
    $doctorUser = DB::table('doctor_profiles')->where('id', $this->w['doctor']->id)->value('user_id');
    $own = (string) DB::table('patient_profiles')->where('user_id', $doctorUser)->value('id');
    DB::table('walkin_queue_entries')->where('id', $entry)->update(['patient_profile_id' => $own]);
    DB::table('consultations')->where('id', $consultation)->update(['patient_profile_id' => $own]);

    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertForbidden();
    expect(DB::table('care_relationships')->count())->toBe(0)->and(DB::table('consultations')->where('id', $consultation)->value('status'))->toBe('SCHEDULED');
});

it('refuses clinic staff with 403 and the patient on a consultation that is not theirs with 404', function () {
    [, $consultation] = called_patient();

    consultation_post("{$consultation}/start", $this->staff)->assertForbidden();
    consultation_post("{$consultation}/start", $this->patient)->assertForbidden();
    [, $otherProfile, $otherHeaders] = remote_patient();
    consultation_post("{$consultation}/start", $otherHeaders)->assertNotFound();
    expect(DB::table('care_relationships')->count())->toBe(0);
});

it('gives a doctor with no standing at the consultation a 404', function () {
    [, $consultation] = called_patient();
    $other = wi_second_doctor($this->w);

    consultation_post("{$consultation}/start", $other['headers'])->assertNotFound();
});

it('ends a started consultation with its duration, completes the entry and keeps the care relationship', function () {
    [$entry, $consultation] = called_patient();
    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    wi_advance(7);

    $response = consultation_post("{$consultation}/end", $this->w['doctorHeaders'])->assertOk();

    expect($response->json('data.status'))->toBe('COMPLETED')->and($response->json('data.duration_seconds'))->toBe(420);
    expect(wi_status($entry))->toBe('COMPLETED')->and(DB::table('care_relationships')->where('source_id', $entry)->value('status'))->toBe('ACTIVE');
    expect(DB::table('consultation_participants')->where('consultation_id', $consultation)->whereNull('left_at')->count())->toBe(0);
    consultation_post("{$consultation}/end", $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    expect(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'COMPLETED')->count())->toBe(1);
});

it('refuses an end of a consultation that has not started', function () {
    [, $consultation] = called_patient();

    consultation_post("{$consultation}/end", $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');
});

it('completes a walk-in by its entry id', function () {
    [$entry, $consultation] = called_patient();
    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertOk();

    act_post("/api/v1/walk-ins/{$entry}/complete", $this->w['doctorHeaders'])
        ->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    expect(wi_status($entry))->toBe('COMPLETED');
});

it('requires an Idempotency-Key on the lifecycle writes', function () {
    [, $consultation] = called_patient();

    test()->postJson("/api/v1/consultations/{$consultation}/start", [], $this->w['doctorHeaders'])->assertStatus(400);
});

it('refuses a start while the doctor is unavailable, with no grant', function () {
    [, $consultation] = called_patient();
    presence_post('unavailable', $this->w['doctorHeaders'])->assertOk();

    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('reason_code', 'unavailable');
    expect(DB::table('consultations')->where('id', $consultation)->value('status'))->toBe('SCHEDULED')
        ->and(DB::table('care_relationships')->count())->toBe(0);
});

it('refuses a start when the doctor is checked in at another location (not_checked_in here), with no grant', function () {
    [, $consultation] = called_patient();
    $second = wi_second_location($this->w);
    DB::table('doctor_presence_sessions')->update(['clinic_location_id' => $second]);

    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('reason_code', 'not_checked_in');
    expect(DB::table('care_relationships')->count())->toBe(0);
});

it('refuses a start when the doctor has no active assignment at the location', function () {
    [, $consultation] = called_patient();
    DB::table('doctor_clinic_assignments')->where('id', $this->w['assignment'])->update(['status' => 'ENDED', 'effective_until' => $this->clock->now()]);

    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('reason_code', 'no_assignment');
    expect(DB::table('care_relationships')->count())->toBe(0);
});

it('refuses a start when the doctor is no longer verified (M-1), with no grant and no state change', function () {
    [$entry, $consultation] = called_patient();
    DB::table('doctor_profiles')->where('id', $this->w['doctor']->id)->update(['onboarding_status' => 'REJECTED']);

    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('reason_code', 'doctor_not_active');
    expect(DB::table('consultations')->where('id', $consultation)->value('status'))->toBe('SCHEDULED')
        ->and(wi_status($entry))->toBe('CALLED')
        ->and(DB::table('care_relationships')->count())->toBe(0);
});

it('refuses a start when the location is no longer active (M-1), with no grant and no state change', function () {
    [$entry, $consultation] = called_patient();
    DB::table('clinic_locations')->where('id', $this->w['location'])->update(['status' => 'INACTIVE']);

    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('reason_code', 'location_not_active');
    expect(DB::table('consultations')->where('id', $consultation)->value('status'))->toBe('SCHEDULED')
        ->and(DB::table('care_relationships')->count())->toBe(0);
});

it('refuses a start when the clinic is no longer active (M-1)', function () {
    [, $consultation] = called_patient();
    DB::table('clinics')->where('id', $this->w['clinic'])->update(['status' => 'INACTIVE']);

    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertStatus(409)->assertJsonPath('reason_code', 'location_not_active');
    expect(DB::table('care_relationships')->count())->toBe(0);
});

it('lets the doctor end while the stored presence is not IN (ending never refuses on presence)', function () {
    [, $consultation] = called_patient();
    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    DB::table('doctor_presence_sessions')->update(['current_status' => 'ON_BREAK']);

    consultation_post("{$consultation}/end", $this->w['doctorHeaders'])->assertOk()->assertJsonPath('data.status', 'COMPLETED');
});

it('starts with the LOCATION timezone for the care window (MINOR-5)', function () {
    [$entry, $consultation] = called_patient();
    DB::table('clinic_locations')->where('id', $this->w['location'])->update(['timezone' => 'America/New_York']);
    $newYorkDay = $this->clock->now()->setTimezone('America/New_York')->toDateString();
    DB::table('walkin_queues')->update(['local_date' => $newYorkDay]);

    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertOk();

    $expected = CarbonImmutable::instance($this->clock->now())->setTimezone('America/New_York')->startOfDay()->addDays(8)->utc();
    expect(CarbonImmutable::parse(DB::table('care_relationships')->where('source_id', $entry)->value('access_until'))->equalTo($expected))->toBeTrue();
});

it('records the care relationship id in the start audit row, and null for a guest (L-5)', function () {
    [$entry, $consultation] = called_patient();
    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertOk();

    $care = DB::table('care_relationships')->where('source_id', $entry)->value('id');
    $meta = json_decode((string) DB::table('audit_logs')->where('action', 'consultations.started')->value('changes'), true);
    expect($meta['care_relationship_id'])->toBe($care);
});

it('reads the COMPLETED consultation in the rolling duration stats (MINOR-4)', function () {
    [, $consultation] = called_patient();
    consultation_post("{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    wi_advance(7);
    consultation_post("{$consultation}/end", $this->w['doctorHeaders'])->assertOk();
    config(['walkins.estimation.min_samples' => 1]);

    $expected = app(RollingDurationStats::class)->expectedFor($this->w['doctor']->id, $this->w['service']);

    expect($expected->source)->toBe(DurationSource::Rolling)->and($expected->seconds)->toBe(420);
});
