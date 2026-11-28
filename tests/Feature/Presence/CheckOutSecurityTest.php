<?php

use App\Modules\Appointments\Contracts\CheckOutAppointments;
use App\Modules\Consultations\Models\Consultation;
use App\Modules\Consultations\Services\ConsultationLifecycle;
use App\Modules\Presence\Enums\CheckOutReason;
use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Enums\CheckOutDisposition;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;

/*
 * Increment 5.8-T: check-out security (brief §4 5.8-T). Authorisation failures (another clinic, another location, another doctor, the
 * wrong role), what the preview may reveal (HAG-29, the audited ADMIN read), what notices, audit and event rows may hold (ids and codes
 * only), and a forced failure after the appointment flag, which must roll back everything. Patient entries are used wherever a patient
 * is needed; a guest is named only to prove that the name never leaves the queue.
 */

const COS_GUEST_NAME = 'Alpha Guest Zeta';

/** A guest the staff register (WAITING at once), named so the name can be searched for in the output. Returns the entry id. */
function cos_guest(array $w, array $staffHeaders): string
{
    app('auth')->forgetGuards();

    return register_walkin($staffHeaders, guest_body($w['service'], COS_GUEST_NAME))->assertCreated()->json('data.id');
}

/** A patient registered and arrived (WAITING) through the API. Returns the entry id. */
function cos_patient(array $w, array $headers, string $profile): string
{
    app('auth')->forgetGuards();
    $entry = register_walkin($headers, patient_body($w['service'], $profile))->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
    wi_post("{$entry}/arrive", test()->staff)->assertOk();

    return $entry;
}

/** A patient called and started (IN_PROGRESS) by the doctor. Returns [entry id, consultation id]. */
function cos_running(array $w, array $headers, string $profile): array
{
    $entry = cos_patient($w, $headers, $profile);
    app('auth')->forgetGuards();
    wi_call_next(wi_queue_id(), test()->staff)->assertOk()->assertJsonPath('data.id', $entry);
    $consultation = (string) Consultation::query()->where('walkin_queue_entry_id', $entry)->value('id');
    app(ConsultationLifecycle::class)->start(User::query()->findOrFail($w['doctor']->user_id), Consultation::query()->findOrFail($consultation));

    return [$entry, $consultation];
}

/** The check-out preview for a doctor and location. */
function cos_preview(array $headers, array $query = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->getJson('/api/v1/presence/check-out/preview?'.http_build_query($query), $headers);
}

/** Every value in a table, as one string, for the "no name leaves the queue" scan. */
function cos_table_text(string $table): string
{
    return (string) json_encode(DB::table($table)->get()->all());
}

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    consultation_clock($this->clock);
    $this->w = walkin_world(['remote' => true]);
    $this->staff = staff_of($this->w);
    doctor_in($this->w);
    [$this->user, $this->profile, $this->patient] = remote_patient();
    $this->doctorId = (string) $this->w['doctor']->id;
    $this->doctorUserId = (string) $this->w['doctor']->user_id;
    $this->body = ['reason' => 'END_OF_SHIFT', 'walkin_disposition' => 'HOLD_FOR_REASSIGNMENT', 'doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']];
});

// Authorisation failures.

it('refuses another clinic\'s manager and staff, a clinic manager restricted to another location, and a patient, finance and support user (403) on check-out and preview', function () {
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    $restricted = clinic_member($this->w['clinic'], Role::ClinicManager, wi_second_location($this->w));
    $finance = session_for(account(Role::FinanceOfficer));
    $support = session_for(account(Role::SupportAgent));
    $otherStaff = clinic_member($other['clinic'], Role::ClinicStaff)[1];
    $restrictedStaff = clinic_member($this->w['clinic'], Role::ClinicStaff, wi_second_location($this->w))[1];

    foreach ([$other['manager'], $otherStaff, $restricted[1], $restrictedStaff, $this->patient, $finance, $support] as $headers) {
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/presence/check-out', $this->body, $headers)->assertForbidden();
        cos_preview($headers, ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])->assertForbidden();
    }

    expect(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('current_status'))->toBe('IN')
        ->and(DB::table('doctor_presence_events')->where('new_status', 'OUT')->count())->toBe(0);
});

it('logs PRESENCE_ONBEHALF_DENIED for each refused on-behalf check-out: another clinic\'s manager and staff, and staff restricted to another location', function () {
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    $callers = [
        $other['manager'],
        clinic_member($other['clinic'], Role::ClinicStaff)[1],
        clinic_member($this->w['clinic'], Role::ClinicStaff, wi_second_location($this->w))[1],
    ];

    foreach ($callers as $headers) {
        Log::spy();
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/presence/check-out', $this->body, $headers)->assertForbidden();

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Presence action on behalf refused'
            && ($context['metric'] ?? null) === 'PRESENCE_ONBEHALF_DENIED' && ($context['doctor_id'] ?? null) === $this->doctorId)->atLeast()->once();
    }
});

it('refuses a doctor checking out, or previewing, another doctor\'s session at this location (403)', function () {
    $second = wi_second_doctor($this->w);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', $this->body, $second['headers'])->assertForbidden();
    cos_preview($second['headers'], ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])->assertForbidden();

    expect(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('current_status'))->toBe('IN');
});

// What the preview may reveal.

it('the preview leaks no name and no phone number of a guest or a patient', function () {
    $entry = cos_guest($this->w, $this->staff);
    cos_patient($this->w, $this->patient, $this->profile);

    $body = cos_preview($this->w['doctorHeaders'], ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])->assertOk()->getContent();

    expect($body)->not->toContain(COS_GUEST_NAME)->and($body)->not->toContain('Alpha')
        ->and($body)->not->toContain('Ana')->and($body)->not->toContain('Santos')
        ->and($body)->not->toMatch('/\+?63\d{9,10}|\b09\d{9}\b/')
        ->and($body)->toContain($entry);
});

it('a SUPER_ADMIN preview of a running consultation gets the status but no consultation id (HAG-29)', function () {
    [, $consultation] = cos_running($this->w, $this->patient, $this->profile);
    $superAdmin = admin_session(Role::SuperAdmin)[1];

    $r = cos_preview($superAdmin, ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']]);

    $r->assertOk()->assertJsonPath('data.active_consultation.status', 'IN_PROGRESS')->assertJsonPath('data.active_consultation.id', null);
    expect($r->getContent())->not->toContain($consultation)
        ->and(DB::table('audit_logs')->where('action', 'consultations.viewed')->count())->toBe(0);
});

it('an ADMIN preview of a running consultation carries its id, and the read is audited as a 5.7 access event with via check_out_preview', function () {
    [, $consultation] = cos_running($this->w, $this->patient, $this->profile);
    $admin = admin_session(Role::Admin)[1];

    cos_preview($admin, ['doctor_id' => $this->doctorId, 'clinic_location_id' => $this->w['location']])
        ->assertOk()->assertJsonPath('data.active_consultation.id', $consultation);

    $audit = DB::table('audit_logs')->where('action', 'consultations.viewed')->where('subject_id', $consultation)->sole();
    expect(json_decode((string) $audit->changes, true))->toMatchArray(['via' => 'check_out_preview'])
        ->and((bool) $audit->is_access_event)->toBeTrue();
});

// What the notices, audit rows and events may hold.

it('the notices, audit rows, events and queue events carry no guest name and no reason text, after a check-out that cancels a guest', function () {
    cos_guest($this->w, $this->staff);
    cos_patient($this->w, $this->patient, $this->profile);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-out', [...$this->body, 'walkin_disposition' => 'NOTIFY_AND_CANCEL', 'guests_informed_in_person' => true], $this->w['doctorHeaders'])
        ->assertOk();

    $sinks = ['notifications', 'audit_logs', 'doctor_presence_events', 'doctor_presence_sessions', 'walkin_queue_events', 'walkin_queue_entries'];
    foreach ($sinks as $table) {
        expect(cos_table_text($table))->not->toContain(COS_GUEST_NAME, 'name leaked into '.$table)->not->toContain('Alpha Guest');
    }
    expect(cos_table_text('notifications'))->not->toContain('END_OF_SHIFT')->and(cos_table_text('audit_logs'))->toContain('presence.checked_out');
    // The sinks are non-empty, so the absence scans above mean something: both entries cancelled, and the walk-in notices written.
    expect(DB::table('walkin_queue_events')->where('event_type', 'CANCELLED')->count())->toBe(2)
        ->and(DB::table('notifications')->where('type', 'like', 'walkins.%')->count())->toBeGreaterThan(0);
});

it('a failure after the appointment flag rolls back the whole check-out: session open, entries and queue untouched, no task, no event', function () {
    $entry = cos_patient($this->w, $this->patient, $this->profile);
    $start = $this->clock->now()->addHour();
    wi_appointment($this->w, $start, $start->addMinutes(30));

    app()->extend(CheckOutAppointments::class, fn (CheckOutAppointments $real) => new class($real) implements CheckOutAppointments
    {
        public function __construct(private readonly CheckOutAppointments $inner) {}

        public function remaining(string $doctorProfileId, string $locationId, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): array
        {
            return $this->inner->remaining($doctorProfileId, $locationId, $fromUtc, $toUtc);
        }

        public function flagForCheckOut(string $doctorProfileId, string $locationId, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): array
        {
            $this->inner->flagForCheckOut($doctorProfileId, $locationId, $fromUtc, $toUtc);

            throw new RuntimeException('forced failure after the appointment flag');
        }
    });

    $user = User::query()->findOrFail($this->doctorUserId);
    expect(fn () => app(PresenceCheckOut::class)->checkOut(presence_actor($user), $this->doctorId, $this->w['location'], CheckOutReason::EndOfShift, CheckOutDisposition::HoldForReassignment, false))
        ->toThrow(RuntimeException::class, 'forced failure after the appointment flag');

    expect(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('current_status'))->toBe('IN')
        ->and(DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctorId)->value('checked_out_at'))->toBeNull()
        ->and(wi_status($entry))->toBe('WAITING')
        ->and(DB::table('walkin_queues')->where('doctor_profile_id', $this->doctorId)->value('status'))->toBe('OPEN')
        ->and(DB::table('walkin_queue_events')->where('event_type', 'QUEUE_PAUSED')->count())->toBe(0)
        ->and(DB::table('doctor_presence_events')->where('new_status', 'OUT')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'presence.checked_out')->count())->toBe(0)
        ->and(DB::table('rescheduling_tasks')->count())->toBe(0)
        ->and((bool) DB::table('appointments')->where('doctor_profile_id', $this->doctorId)->value('needs_rescheduling'))->toBeFalse()
        ->and(DB::table('notifications')->where('type', 'walkins.doctor_checked_out')->count())->toBe(0);
});
