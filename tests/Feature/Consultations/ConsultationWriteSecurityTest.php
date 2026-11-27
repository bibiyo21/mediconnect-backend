<?php

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Services\ConsultationLifecycle;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Increment 5.7-T, write-path security (brief §7; security re-verify condition C-2 for L-4, and C-1 for the guest case of L-5).
 * Who may start, end or complete a walk-in consultation: only its own doctor (B1, not granted to staff or patients). No standing (the
 * consultation is not theirs, and they hold no view at its location) is 404; standing without the write right is 403 (D-O). A doctor is never
 * the treating doctor of their own patient profile (D-16). Every refusal leaves no care row and no state change.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(RbacSeeder::class);
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    consultation_clock($this->clock);
    $this->w = walkin_world(['remote' => true]);
    doctor_in($this->w);
    $this->doctorUser = User::query()->findOrFail($this->w['doctor']->user_id);
    $this->staffHeaders = staff_of($this->w);
    [$this->userA, $this->profileA, $this->headersA] = remote_patient();
    // The other doctor, the other clinic's staff, another patient and two roles with no walk-in standing.
    $this->other = wi_second_doctor($this->w);
    [$this->otherClinic, $this->otherLocation, $this->otherManager] = clinic_with_manager('Other Clinic');
    [, $this->otherClinicStaff] = clinic_member($this->otherClinic, Role::ClinicStaff);
    [, $this->otherPatientProfile, $this->otherPatientHeaders] = remote_patient();
    $this->financeHeaders = session_for(account(Role::FinanceOfficer));
    $this->supportHeaders = session_for(account(Role::SupportAgent));
    app('auth')->forgetGuards();
});

/** A remote patient registered, arrived and called through the API; returns [entry id, consultation id]. */
function ws_called(array $headers, string $profile): array
{
    app('auth')->forgetGuards();
    $entry = register_walkin($headers, patient_body(test()->w['service'], $profile))->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    wi_post("{$entry}/arrive", test()->staffHeaders)->assertOk();
    app('auth')->forgetGuards();
    wi_call_next(wi_queue_id(), test()->staffHeaders)->assertOk()->assertJsonPath('data.id', $entry);

    return [$entry, (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id')];
}

/** A guest (named, with a phone) registered and called by staff; returns [entry id, consultation id]. */
function ws_guest_called(string $name): array
{
    $entry = wi_guest(test()->staffHeaders, test()->w['service'], $name);
    app('auth')->forgetGuards();
    wi_call_next(wi_queue_id(), test()->staffHeaders)->assertOk()->assertJsonPath('data.id', $entry);

    return [$entry, (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id')];
}

function ws_post(string $path, array $headers)
{
    return act_post("/api/v1/{$path}", $headers);
}

function ws_status(string $consultation): string
{
    return (string) DB::table('consultations')->where('id', $consultation)->value('status');
}

function ws_grants(string $entry): int
{
    return DB::table('care_relationships')->where('source_id', $entry)->count();
}

it('refuses another doctor on start, end and complete with 404, and changes nothing', function () {
    [$entry, $consultation] = ws_called($this->headersA, $this->profileA);
    ws_post("consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    $grants = ws_grants($entry);

    ws_post("consultations/{$consultation}/start", $this->other['headers'])->assertNotFound();
    ws_post("consultations/{$consultation}/end", $this->other['headers'])->assertNotFound();
    ws_post("walk-ins/{$entry}/complete", $this->other['headers'])->assertNotFound();

    expect(ws_status($consultation))->toBe('IN_PROGRESS')->and(ws_grants($entry))->toBe($grants);
});

it('refuses another doctor on start before the consultation runs, with no grant', function () {
    [$entry, $consultation] = ws_called($this->headersA, $this->profileA);

    ws_post("consultations/{$consultation}/start", $this->other['headers'])->assertNotFound();

    expect(ws_status($consultation))->toBe('SCHEDULED')->and(ws_grants($entry))->toBe(0);
});

it('refuses clinic staff of the same clinic with 403 on start, end and complete (B1: staff may view, not change)', function () {
    [$entry, $consultation] = ws_called($this->headersA, $this->profileA);

    ws_post("consultations/{$consultation}/start", $this->staffHeaders)->assertForbidden();
    ws_post("walk-ins/{$entry}/complete", $this->staffHeaders)->assertForbidden();

    expect(ws_status($consultation))->toBe('SCHEDULED')->and(ws_grants($entry))->toBe(0);
    ws_post("consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    ws_post("consultations/{$consultation}/end", $this->staffHeaders)->assertForbidden();
    expect(ws_status($consultation))->toBe('IN_PROGRESS');
});

it('refuses clinic staff of an unrelated clinic with 404', function () {
    [$entry, $consultation] = ws_called($this->headersA, $this->profileA);

    ws_post("consultations/{$consultation}/start", $this->otherClinicStaff)->assertNotFound();
    ws_post("walk-ins/{$entry}/complete", $this->otherClinicStaff)->assertNotFound();

    expect(ws_status($consultation))->toBe('SCHEDULED')->and(ws_grants($entry))->toBe(0);
});

it('refuses the patient of the consultation with 403 on start and end (they may view it, not change it)', function () {
    [$entry, $consultation] = ws_called($this->headersA, $this->profileA);

    ws_post("consultations/{$consultation}/start", $this->headersA)->assertForbidden();

    expect(ws_status($consultation))->toBe('SCHEDULED')->and(ws_grants($entry))->toBe(0);
    ws_post("consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    ws_post("consultations/{$consultation}/end", $this->headersA)->assertForbidden();
    ws_post("walk-ins/{$entry}/complete", $this->headersA)->assertForbidden();
    expect(ws_status($consultation))->toBe('IN_PROGRESS');
});

it('refuses another patient with 404 on start, end and complete', function () {
    [$entry, $consultation] = ws_called($this->headersA, $this->profileA);

    ws_post("consultations/{$consultation}/start", $this->otherPatientHeaders)->assertNotFound();
    ws_post("walk-ins/{$entry}/complete", $this->otherPatientHeaders)->assertNotFound();

    expect(ws_status($consultation))->toBe('SCHEDULED')->and(ws_grants($entry))->toBe(0);
});

it('refuses finance and support staff, who hold no consultation standing here, with no change', function () {
    [$entry, $consultation] = ws_called($this->headersA, $this->profileA);

    ws_post("consultations/{$consultation}/start", $this->financeHeaders)->assertNotFound();
    // Support holds consultations.view globally (G), so it may see the consultation and is refused the write with 403.
    ws_post("consultations/{$consultation}/start", $this->supportHeaders)->assertForbidden();
    ws_post("walk-ins/{$entry}/complete", $this->financeHeaders)->assertNotFound();

    expect(ws_status($consultation))->toBe('SCHEDULED')->and(ws_grants($entry))->toBe(0);
});

it('refuses a request with no session with 401', function () {
    [$entry, $consultation] = ws_called($this->headersA, $this->profileA);

    act_post("/api/v1/consultations/{$consultation}/start", [])->assertUnauthorized();
    act_post("/api/v1/walk-ins/{$entry}/complete", [])->assertUnauthorized();

    expect(ws_status($consultation))->toBe('SCHEDULED')->and(ws_grants($entry))->toBe(0);
});

it('refuses a doctor who is the patient of the consultation (D-16) with 403, no grant and no state change', function () {
    // The doctor's own account also holds a patient profile; staff call that profile's entry (the doctor is never the treating doctor).
    $own = PatientProfile::query()->where('user_id', $this->doctorUser->id)->first()
        ?? PatientProfile::factory()->create(['user_id' => $this->doctorUser->id, 'first_name' => 'Dr', 'last_name' => 'Self', 'date_of_birth' => '1980-01-01']);
    DB::table('users')->where('id', $this->doctorUser->id)->update(['mobile_e164' => '+639171230001', 'mobile_verified_at' => now()]);
    app('auth')->forgetGuards();
    [$entry, $consultation] = ws_called(session_for($this->doctorUser->refresh()), (string) $own->id);

    ws_post("consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertForbidden();

    expect(ws_status($consultation))->toBe('SCHEDULED')->and(ws_grants($entry))->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'consultations.started')->count())->toBe(0);
});

it('rolls back a start whose audit write fails after the grant: no care row, consultation SCHEDULED, entry still CALLED', function () {
    [$entry, $consultation] = ws_called($this->headersA, $this->profileA);
    app()->forgetInstance(ConsultationLifecycle::class);
    app()->instance(RecordsAudit::class, new class implements RecordsAudit
    {
        public function record(string $action, ?string $subjectType = null, ?string $subjectId = null, array $changes = [], bool $accessEvent = false, ?string $clinicId = null): void
        {
            throw new RuntimeException('audit store unavailable');
        }
    });

    expect(fn () => app(ConsultationLifecycle::class)->start($this->doctorUser, Consultation::query()->findOrFail($consultation)))
        ->toThrow(RuntimeException::class, 'audit store unavailable');

    expect(ws_status($consultation))->toBe('SCHEDULED')
        ->and(ws_grants($entry))->toBe(0)
        ->and(wi_status($entry))->toBe('CALLED')
        ->and(DB::table('walkin_queue_events')->where('walkin_queue_entry_id', $entry)->where('event_type', 'STARTED')->count())->toBe(0);
});

it('writes a null care_relationship_id in the start audit row for a guest (L-5 guest case, condition C-1)', function () {
    [$entry, $consultation] = ws_guest_called('Guest Audit Row');

    ws_post("consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();

    $changes = json_decode((string) DB::table('audit_logs')->where('action', 'consultations.started')->value('changes'), true);
    expect($changes)->toHaveKey('care_relationship_id')->and($changes['care_relationship_id'])->toBeNull()
        ->and($changes)->toHaveKey('is_guest')->and($changes['is_guest'])->toBeTrue()
        ->and(ws_grants($entry))->toBe(0);
});

it('records is_guest false in the start audit row of a patient, with a care row (L-2 flag, not redacted)', function () {
    [$entry, $consultation] = ws_called($this->headersA, $this->profileA);

    ws_post("consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();

    $changes = json_decode((string) DB::table('audit_logs')->where('action', 'consultations.started')->value('changes'), true);
    expect($changes)->toHaveKey('is_guest')->and($changes['is_guest'])->toBeFalse()
        ->and($changes['care_relationship_id'])->not->toBeNull()
        ->and(ws_grants($entry))->toBe(1);
});

it('refuses another doctor, another patient and finance on interrupt and resume with 404 and no state change (L-5)', function () {
    [$entry, $consultation] = ws_called($this->headersA, $this->profileA);
    ws_post("consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    $grants = ws_grants($entry);

    foreach ([$this->other['headers'], $this->otherPatientHeaders, $this->financeHeaders] as $headers) {
        ws_post("consultations/{$consultation}/interrupt", $headers)->assertNotFound();
    }
    expect(ws_status($consultation))->toBe('IN_PROGRESS');

    ws_post("consultations/{$consultation}/interrupt", $this->w['doctorHeaders'])->assertOk();
    foreach ([$this->other['headers'], $this->otherPatientHeaders, $this->financeHeaders] as $headers) {
        ws_post("consultations/{$consultation}/resume", $headers)->assertNotFound();
    }

    expect(ws_status($consultation))->toBe('INTERRUPTED')->and(ws_grants($entry))->toBe($grants);
});

it('refuses the patient of the consultation with 403 on interrupt and resume (they may view it, not change it) (L-5)', function () {
    [$entry, $consultation] = ws_called($this->headersA, $this->profileA);
    ws_post("consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();

    ws_post("consultations/{$consultation}/interrupt", $this->headersA)->assertForbidden();
    expect(ws_status($consultation))->toBe('IN_PROGRESS');

    ws_post("consultations/{$consultation}/interrupt", $this->w['doctorHeaders'])->assertOk();
    ws_post("consultations/{$consultation}/resume", $this->headersA)->assertForbidden();
    expect(ws_status($consultation))->toBe('INTERRUPTED');
});

it('keeps a guest name and phone out of audit rows, queue events and the log stream through start and end', function () {
    $name = 'Scrub Probe Guest';
    $phone = '09171234567';
    $phoneTail = '9171234567';
    $logs = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs) {
        $logs[] = [$event->message, $event->context];
    });
    [$entry, $consultation] = ws_guest_called($name);

    ws_post("consultations/{$consultation}/start", $this->w['doctorHeaders'])->assertOk();
    ws_post("consultations/{$consultation}/end", $this->w['doctorHeaders'])->assertOk();

    $stored = json_encode([
        DB::table('audit_logs')->get()->all(),
        DB::table('walkin_queue_events')->get()->all(),
    ]);
    // The stored phone is E.164 (+639171234567), so the national tail is the probe that can actually catch a leak.
    expect($stored)->not->toContain($name)->not->toContain($phone)->not->toContain($phoneTail)
        ->and(json_encode($logs))->not->toContain($name)->not->toContain($phone)->not->toContain($phoneTail);
});
