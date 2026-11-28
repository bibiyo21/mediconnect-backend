<?php

use App\Modules\Consultations\Models\Consultation;
use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\Rbac\Enums\Role;
use App\Shared\Contracts\Clock;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

/*
 * Increment 5.8-1: the check-out preview (brief D-I). Advisory and read-only: it lists what a check-out would find, shows entry ids only to a
 * reader with walkins.view, a consultation id only to a reader with consultations.view in scope (HAG-29: never SUPER_ADMIN), and no names,
 * phone numbers or patient ids anywhere.
 */

/** A walk-in patient called and started: [entry id, consultation id]. */
function cp_in_progress(array $w, array $staff, array $patientHeaders, string $profile): array
{
    fresh_session();
    $entry = register_walkin($patientHeaders, patient_body($w['service'], $profile))->assertCreated()->json('data.id');
    fresh_session();
    wi_post("{$entry}/arrive", $staff)->assertOk();
    fresh_session();
    wi_call_next(wi_queue_id(), $staff)->assertOk();
    $consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id');
    act_post("/api/v1/consultations/{$consultation}/start", $w['doctorHeaders'])->assertOk();

    return [$entry, $consultation];
}

function cp_preview(array $headers, array $query = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->getJson('/api/v1/presence/check-out/preview?'.http_build_query($query), $headers);
}

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    consultation_clock($this->clock);
    $this->w = walkin_world(['remote' => true]);
    $this->svc = $this->w['service'];
    $this->staff = staff_of($this->w);
    doctor_in($this->w);
    [$this->user, $this->profile, $this->patient] = remote_patient();
    $this->doctorId = (string) $this->w['doctor']->id;
});

it('lists the whole picture with exactly the whitelisted keys, and the doctor sees her own entry ids and counts', function () {
    fresh_session();
    $entry = register_walkin($this->patient, patient_body($this->svc, $this->profile))->assertCreated()->json('data.id');
    fresh_session();
    wi_post("{$entry}/arrive", $this->staff)->assertOk();

    $r = cp_preview($this->w['doctorHeaders'], ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])->assertOk();

    expect(array_keys($r->json('data')))->toEqualCanonicalizing(['session', 'blocking', 'active_consultation', 'queues', 'called_entry_ids', 'live_entry_ids', 'appointments']);
    expect($r->json('data.session.status'))->toBe('IN')->and($r->json('data.session.version'))->toBe(1)
        ->and($r->json('data.blocking'))->toBe([])->and($r->json('data.active_consultation'))->toBeNull()
        ->and($r->json('data.queues.0.counts.waiting'))->toBe(1)->and($r->json('data.queues.0.status'))->toBe('OPEN')
        ->and($r->json('data.live_entry_ids'))->toBe([$entry])->and($r->json('data.called_entry_ids'))->toBe([])
        ->and($r->json('data.appointments'))->toBe(['count' => 0, 'items' => []]);
    expect(array_keys($r->json('data.queues.0')))->toEqualCanonicalizing(['queue_id', 'status', 'counts', 'guest_count']);
    expect(array_keys($r->json('data.queues.0.counts')))->toEqualCanonicalizing(['registered', 'waiting', 'called', 'skipped', 'in_consultation']);
    expect(array_keys($r->json('data.session')))->toEqualCanonicalizing(['id', 'status', 'version']);
});

it('pins the nested keys of an in-progress block and of the appointment items (no patient or booker field)', function () {
    [, $consultation] = cp_in_progress($this->w, $this->staff, $this->patient, $this->profile);
    $start = $this->clock->now()->addHour();
    wi_appointment($this->w, $start, $start->addMinutes(30));

    $r = cp_preview($this->w['doctorHeaders'], ['clinic_location_id' => $this->w['location']])->assertOk();
    expect(array_keys($r->json('data.active_consultation')))->toEqualCanonicalizing(['id', 'status'])
        ->and($r->json('data.active_consultation.id'))->toBe($consultation)
        ->and(array_keys($r->json('data.appointments.items.0')))->toEqualCanonicalizing(['id', 'starts_at']);
});

it('hides the walk-in entry ids from a staff member who lost walkins.view at the location (the null branch)', function () {
    $entry = register_walkin($this->patient, patient_body($this->svc, $this->profile))->assertCreated()->json('data.id');
    [, $headers] = clinic_member($this->w['clinic'], Role::ClinicStaff, $this->w['location']);
    // Remove walkins.view from the staff role for this test only; the transaction rolls it back. presence.manage stays.
    Spatie\Permission\Models\Role::query()->where('name', Role::ClinicStaff->value)->get()->each->revokePermissionTo('walkins.view');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $r = cp_preview($headers, ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])->assertOk();
    expect($r->json('data.live_entry_ids'))->toBeNull()->and($r->json('data.called_entry_ids'))->toBeNull()
        ->and($r->getContent())->not->toContain($entry);
});

it('never reveals a stranger doctor\'s consultation at this clinic: no read, no id, no block (5.8 M1)', function () {
    $other = walkin_world(['remote' => true, 'clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    doctor_in($other);
    [, $otherProfile, $otherHeaders] = remote_patient();
    $otherStaff = staff_of($other);
    // The other clinic's walk-in, called and started, on its own queue (not wi_queue_id: two worlds share the table).
    fresh_session();
    $entry = register_walkin($otherHeaders, patient_body($other['service'], $otherProfile))->assertCreated()->json('data.id');
    fresh_session();
    wi_post("{$entry}/arrive", $otherStaff)->assertOk();
    fresh_session();
    $queue = (string) DB::table('walkin_queues')->where('doctor_profile_id', $other['doctor']->id)->value('id');
    wi_call_next($queue, $otherStaff)->assertOk();
    $consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id');
    act_post("/api/v1/consultations/{$consultation}/start", $other['doctorHeaders'])->assertOk();

    $r = cp_preview($this->w['manager'], ['doctor_id' => (string) $other['doctor']->id, 'clinic_location_id' => $this->w['location']])->assertOk();

    $r->assertJsonPath('data.active_consultation', null)->assertJsonPath('data.blocking.0.reason_code', 'not_checked_in');
    expect($r->getContent())->not->toContain($consultation)
        ->and(DB::table('audit_logs')->where('action', 'consultations.viewed')->count())->toBe(0);
});

it('lists the day\'s live appointments only: not ended, not tomorrow, not cancelled', function () {
    $now = $this->clock->now();
    wi_appointment($this->w, $now->addHour(), $now->addHour()->addMinutes(30));
    wi_appointment($this->w, $now->subHours(3), $now->subHours(2));
    wi_appointment($this->w, $now->addDay()->addHour(), $now->addDay()->addHours(2)->addMinutes(30));
    wi_appointment($this->w, $now->addHours(2), $now->addHours(2)->addMinutes(30));
    DB::table('appointments')->where('doctor_profile_id', $this->doctorId)->where('starts_at', '>', $now->addMinutes(90)->toIso8601String())
        ->where('starts_at', '<', $now->addDay()->toIso8601String())->update(['status' => 'CANCELLED']);

    cp_preview($this->w['doctorHeaders'], ['clinic_location_id' => $this->w['location']])
        ->assertOk()->assertJsonPath('data.appointments.count', 1);
});

it('never returns a name, phone number or patient id', function () {
    $patientEntry = register_walkin($this->patient, patient_body($this->svc, $this->profile))->assertCreated()->json('data.id');
    register_walkin($this->staff, guest_body($this->svc, 'Maria Clara Santos', ['phone' => '09171234567']))->assertCreated();

    $r = cp_preview($this->w['manager'], ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])->assertOk();
    $body = $r->getContent();

    // The patient's entry is in the list (so the patient profile id would be the thing to leak), and the guest's number is stored +63.
    expect($r->json('data.live_entry_ids'))->toContain($patientEntry)
        ->and($body)->not->toContain('Maria Clara Santos')->and($body)->not->toContain('9171234567')->and($body)->not->toContain($this->profile);
});

it('shows the clinic manager (walkins.view at the location) the entry ids too, and changes nothing', function () {
    $entry = register_walkin($this->patient, patient_body($this->svc, $this->profile))->assertCreated()->json('data.id');
    $events = DB::table('walkin_queue_events')->count();

    cp_preview($this->w['manager'], ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])
        ->assertOk()->assertJsonPath('data.live_entry_ids.0', $entry);

    expect(wi_status($entry))->toBe('REGISTERED')->and(DB::table('walkin_queue_events')->count())->toBe($events)
        ->and(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('current_status'))->toBe('IN');
});

it('reports the blocks: an in-progress consultation (with its id for the doctor), and a called walk-in', function () {
    [$entry, $consultation] = cp_in_progress($this->w, $this->staff, $this->patient, $this->profile);

    $r = cp_preview($this->w['doctorHeaders'], ['clinic_location_id' => $this->w['location']])->assertOk();
    expect($r->json('data.blocking'))->toContain(['code' => 'ACTIVE_CONSULTATION', 'reason_code' => 'in_progress'])
        ->and($r->json('data.active_consultation'))->toBe(['id' => $consultation, 'status' => 'IN_PROGRESS'])
        ->and($r->json('data.session.status'))->toBe('IN');
});

it('reports a called walk-in as a block, with the called entry id for a reader who may see entries', function () {
    $entry = register_walkin($this->patient, patient_body($this->svc, $this->profile))->assertCreated()->json('data.id');
    fresh_session();
    wi_post("{$entry}/arrive", $this->staff)->assertOk();
    fresh_session();
    wi_call_next(wi_queue_id(), $this->staff)->assertOk();

    $r = cp_preview($this->w['manager'], ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])->assertOk();
    expect($r->json('data.blocking'))->toContain(['code' => 'ACTIVE_CONSULTATION', 'reason_code' => 'called'])
        ->and($r->json('data.called_entry_ids'))->toBe([$entry])->and($r->json('data.live_entry_ids'))->toBe([]);
});

it('gives SUPER_ADMIN the status only: no consultation id (HAG-29), though the block is still shown', function () {
    [, $consultation] = cp_in_progress($this->w, $this->staff, $this->patient, $this->profile);

    $r = cp_preview(admin_session(Role::SuperAdmin)[1], ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']]);

    $r->assertOk()->assertJsonPath('data.active_consultation.id', null)->assertJsonPath('data.active_consultation.status', 'IN_PROGRESS');
    expect($r->getContent())->not->toContain($consultation)
        ->and(DB::table('audit_logs')->where('action', 'consultations.viewed')->count())->toBe(0);
});

it('gives ADMIN the consultation id, and audits that global read as a 5.7 access event', function () {
    [, $consultation] = cp_in_progress($this->w, $this->staff, $this->patient, $this->profile);

    cp_preview(admin_session(Role::Admin)[1], ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])
        ->assertOk()->assertJsonPath('data.active_consultation.id', $consultation);

    $audit = DB::table('audit_logs')->where('action', 'consultations.viewed')->sole();
    expect($audit->subject_id)->toBe($consultation)->and((bool) $audit->is_access_event)->toBeTrue();
});

it('reports the day\'s appointments by id and start time, not who booked them', function () {
    $start = $this->clock->now()->addHour();
    wi_appointment($this->w, $start, $start->addMinutes(30));
    $appointment = (string) DB::table('appointments')->where('doctor_profile_id', $this->doctorId)->value('id');

    cp_preview($this->w['doctorHeaders'], ['clinic_location_id' => $this->w['location']])
        ->assertOk()->assertJsonPath('data.appointments.count', 1)->assertJsonPath('data.appointments.items.0.id', $appointment);
});

it('shows not_checked_in as a block, with a null session, after the doctor has checked out', function () {
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', ['reason' => 'END_OF_SHIFT', 'walkin_disposition' => 'HOLD_FOR_REASSIGNMENT'], $this->w['doctorHeaders'])->assertOk();

    cp_preview($this->w['doctorHeaders'], ['clinic_location_id' => $this->w['location']])
        ->assertOk()->assertJsonPath('data.session', null)->assertJsonPath('data.blocking.0.reason_code', 'not_checked_in');
});

it('refuses a patient (403), and a stranger at another clinic (403)', function () {
    cp_preview($this->patient, ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])->assertForbidden();

    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    cp_preview($other['manager'], ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])->assertForbidden();
});

/**
 * Moves a consultation (and its walk-in queue) to another location. The test role cannot pause the source trigger, so the queue moves first:
 * the consultation trigger then checks the new location against that queue, and passes.
 */
function cp_move_consultation(string $consultation, string $location): void
{
    $queue = DB::table('consultations')->join('walkin_queue_entries', 'walkin_queue_entries.id', '=', 'consultations.walkin_queue_entry_id')
        ->where('consultations.id', $consultation)->value('walkin_queue_entries.walkin_queue_id');
    DB::table('walkin_queues')->where('id', $queue)->update(['clinic_location_id' => $location]);
    DB::table('consultations')->where('id', $consultation)->update(['clinic_location_id' => $location]);
}

it('gates the consultation id on the consultation\'s own location: clinic X sees the block but no id, ADMIN sees the id and an audit row for clinic Y', function () {
    [, $consultation] = cp_in_progress($this->w, $this->staff, $this->patient, $this->profile);
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    cp_move_consultation($consultation, $other['location']);

    cp_preview($this->w['manager'], ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])->assertOk()
        ->assertJsonPath('data.blocking.0.reason_code', 'in_progress')
        ->assertJsonPath('data.active_consultation.id', null)->assertJsonPath('data.active_consultation.status', 'IN_PROGRESS');

    cp_preview(admin_session(Role::Admin)[1], ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])
        ->assertOk()->assertJsonPath('data.active_consultation.id', $consultation);

    $audit = DB::table('audit_logs')->where('action', 'consultations.viewed')->sole();
    expect($audit->subject_id)->toBe($consultation)->and($audit->clinic_id)->toBe($other['clinic']);
});

it('shows a clinic member at A the id of a consultation at A, with no audit row', function () {
    [, $consultation] = cp_in_progress($this->w, $this->staff, $this->patient, $this->profile);

    cp_preview($this->staff, ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])
        ->assertOk()->assertJsonPath('data.active_consultation.id', $consultation);

    expect(DB::table('audit_logs')->where('action', 'consultations.viewed')->count())->toBe(0);
});

it('blocks on a stale CALLED entry at another clinic, but never returns its id in called_entry_ids (5.8 leak fix)', function () {
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111', 'remote' => true]);
    doctor_in($other);
    [, $otherProfile, $otherHeaders] = remote_patient();
    $otherStaff = staff_of($other);
    fresh_session();
    $entry = register_walkin($otherHeaders, patient_body($other['service'], $otherProfile))->assertCreated()->json('data.id');
    fresh_session();
    wi_post("{$entry}/arrive", $otherStaff)->assertOk();
    fresh_session();
    $queue = (string) DB::table('walkin_queues')->where('doctor_profile_id', $other['doctor']->id)->value('id');
    wi_call_next($queue, $otherStaff)->assertOk();
    // The stale hold: the entry is still CALLED and now points at this doctor (the pool that check-out reads).
    DB::table('walkin_queue_entries')->where('id', $entry)->update(['assigned_doctor_profile_id' => $this->doctorId]);

    $r = cp_preview($this->w['manager'], ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])->assertOk();
    expect($r->json('data.blocking'))->toContain(['code' => 'ACTIVE_CONSULTATION', 'reason_code' => 'called'])
        ->and($r->json('data.called_entry_ids'))->toBe([])
        ->and($r->getContent())->not->toContain($entry);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', ['reason' => 'END_OF_SHIFT', 'walkin_disposition' => 'HOLD_FOR_REASSIGNMENT'], $this->w['doctorHeaders'])
        ->assertStatus(409)->assertJsonPath('reason_code', 'called');
});
