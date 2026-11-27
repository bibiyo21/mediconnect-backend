<?php

use App\Modules\Clinics\Http\Controllers\WalkInPolicyController;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Consultations\Services\CareRelationshipService;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Presence\Services\PresenceAdmissionService;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Scheduling\Services\WalkInDayService;
use App\Modules\Users\Models\User;
use App\Modules\WalkIns\Estimation\RollingDurationStats;
use App\Modules\WalkIns\Estimation\WaitTimeEstimator;
use App\Modules\WalkIns\Jobs\PurgeGuestWalkInPii;
use App\Modules\WalkIns\Realtime\QueueRealtime;
use App\Modules\WalkIns\Services\EntryActions;
use App\Modules\WalkIns\Services\EntryPresenter;
use App\Modules\WalkIns\Services\JoinPreview;
use App\Modules\WalkIns\Services\QueueEstimates;
use App\Modules\WalkIns\Services\QueueOperations;
use App\Modules\WalkIns\Services\WalkInReader;
use App\Modules\WalkIns\Services\WalkInRegistration;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Walk-in fixtures (increment 5.5a). A clinic open 08:00-17:00 with a doctor who walks in patients on Mondays 09:00-12:00
 * (slots of 20 minutes plus 5 buffer, so a derived capacity of 7), all through the real APIs. The clock is pinned to the
 * next Monday at 10:00 Manila for every walk-in class that reads it.
 */

/** Pins the clock of the presence classes and of every walk-in class that reads "now". */
function pin_walkin_clock(?CarbonImmutable $at = null): PinnedClock
{
    $clock = pin_clock($at);
    foreach ([
        WalkInRegistration::class, WalkInDayService::class, QueueEstimates::class, EntryPresenter::class, PresenceAdmissionService::class,
        WaitTimeEstimator::class, RollingDurationStats::class, EntryActions::class, WalkInReader::class, JoinPreview::class, PurgeGuestWalkInPii::class,
        WalkInPolicyController::class, QueueRealtime::class, CareRelationshipService::class, QueueOperations::class,
    ] as $consumer) {
        app()->when($consumer)->needs(Clock::class)->give(fn () => $clock);
    }

    return $clock;
}

/**
 * @param  array{walkin_capacity?: int|null, remote?: bool, licence?: string, clinic?: string, shift?: array{string, string}}  $options
 * @return array{clinic: string, location: string, manager: array<string, string>, doctor: DoctorProfile, doctorHeaders: array<string, string>, mode: string, service: string, assignment: string}
 */
function walkin_world(array $options = []): array
{
    $w = presence_world($options['licence'] ?? '0123456', $options['clinic'] ?? 'Santos Family Clinic');
    $service = (string) DB::table('doctor_services')->where('doctor_profile_id', $w['doctor']->id)->where('consultation_type', 'IN_PERSON')->value('id');
    [$from, $to] = $options['shift'] ?? ['09:00', '12:00'];
    $shift = ['day_of_week' => 1, 'doctor_service_mode_id' => $w['mode'], 'starts_at' => $from, 'ends_at' => $to, 'slot_duration_minutes' => 20, 'buffer_minutes' => 5];
    if (array_key_exists('walkin_capacity', $options) && $options['walkin_capacity'] !== null) {
        $shift['walkin_capacity'] = $options['walkin_capacity'];
    }
    test()->postJson('/api/v1/schedules', ['name' => 'Monday walk-ins', 'effective_from' => CarbonImmutable::now('Asia/Manila')->toDateString(), 'status' => 'ACTIVE', 'shifts' => [$shift]], $w['doctorHeaders'])->assertCreated();
    app('auth')->forgetGuards();
    $assignment = (string) DoctorClinicAssignment::where('doctor_profile_id', $w['doctor']->id)->where('clinic_location_id', $w['location'])->value('id');

    if ($options['remote'] ?? false) {
        walkin_policy($w['manager'], $w['clinic'], $assignment, ['allow_patient_app_registration' => true])->assertOk();
    }

    return [...$w, 'service' => $service, 'assignment' => $assignment];
}

function walkin_policy(array $headers, string $clinic, string $assignment, array $body): TestResponse
{
    app('auth')->forgetGuards();

    return test()->patchJson("/api/v1/clinics/{$clinic}/doctor-assignments/{$assignment}/walkin-policy", $body, $headers);
}

function register_walkin(array $headers, array $body, ?string $key = null): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/walk-ins', $body, [...$headers, 'Idempotency-Key' => $key ?? (string) Str::uuid7()]);
}

function guest_body(string $service, string $name = 'Juan Dela Cruz', array $extra = []): array
{
    return ['doctor_service_id' => $service, 'guest' => ['name' => $name, 'phone' => '09171234567'], 'payer_type' => 'SELF_PAY', 'privacy_notice_version' => 'v1', ...$extra];
}

function patient_body(string $service, string $profile, array $extra = []): array
{
    return ['doctor_service_id' => $service, 'patient_profile_id' => $profile, 'payer_type' => 'SELF_PAY', ...$extra];
}

/**
 * A patient account that may join from the app: a verified mobile number on the account.
 *
 * @return array{0: User, 1: string, 2: array<string, string>} the user, their patient profile id and session headers
 */
function remote_patient(bool $verified = true): array
{
    $user = patient_account();
    if ($verified) {
        DB::table('users')->where('id', $user->id)->update(['mobile_e164' => '+639'.random_int(100000000, 999999999), 'mobile_verified_at' => now()]);
    }
    $profile = (string) PatientProfile::where('user_id', $user->id)->value('id');

    return [$user->refresh(), $profile, session_for($user->refresh())];
}

/** Checks the doctor in through the API. */
function doctor_in(array $world): void
{
    checkin($world['doctorHeaders'], $world['location'])->assertSuccessful();
}

/** A clinic staff member of the world's clinic. */
function staff_of(array $world, Role $role = Role::ClinicStaff, ?string $location = null): array
{
    [, $headers] = clinic_member($world['clinic'], $role, $location);

    return $headers;
}

/** The pinned clock's local (Manila) date. */
function walkin_today(): string
{
    return app(Clock::class)->now()->setTimezone('Asia/Manila')->toDateString();
}

/**
 * An entry straight into the database in a queue of another doctor at the world's location today, for limit and listing tests.
 * Returns the entry id. A new doctor and queue each call, so the one-live-entry-per-queue rules never interfere.
 */
function wi_seed_entry(array $world, ?string $profileId, string $status = 'WAITING', ?string $registeredBy = null, string $channel = 'PATIENT_APP', array $extra = []): string
{
    $location = ClinicLocation::findOrFail($world['location']);
    $doctor = directory_doctor('ACTIVE', 'Dr. Seed '.Str::random(5), $location);
    $service = (string) DB::table('doctor_services')->where('doctor_profile_id', $doctor->id)->where('consultation_type', 'IN_PERSON')->value('id');
    $queue = (string) Str::uuid7();
    DB::table('walkin_queues')->insert(['id' => $queue, 'clinic_location_id' => $world['location'], 'doctor_profile_id' => $doctor->id, 'local_date' => test()->clock->now()->setTimezone('Asia/Manila')->toDateString(), 'status' => 'OPEN', 'last_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $id = (string) Str::uuid7();
    DB::table('walkin_queue_entries')->insert([
        'id' => $id, 'walkin_queue_id' => $queue, 'queue_number' => 1, 'patient_profile_id' => $profileId,
        'guest_name_encrypted' => $profileId === null ? encrypt('Seed Guest') : null, 'registered_by_user_id' => $registeredBy ?? $doctor->user_id,
        'registration_channel' => $channel, 'status' => $status, 'payer_type' => 'SELF_PAY', 'doctor_service_id' => $service,
        'privacy_notice_version' => $profileId === null ? 'v1' : null, 'privacy_notice_shown_by_user_id' => $profileId === null ? ($registeredBy ?? $doctor->user_id) : null, 'privacy_notice_shown_at' => $profileId === null ? now() : null,
        'arrived_at' => in_array($status, ['WAITING', 'CALLED', 'IN_CONSULTATION'], true) ? now() : null,
        'queue_sort_at' => $status === 'WAITING' ? now() : null,
        'completed_at' => $status === 'COMPLETED' ? now() : null,
        'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);

    return $id;
}

/** A guardian's dependant (a patient profile without a login), added through the API; returns the dependant's patient profile id. */
function wi_dependent(array $headers): string
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/patients/me/dependents', [
        'first_name' => 'Lia', 'last_name' => 'Santos', 'date_of_birth' => '2018-03-10', 'sex' => 'FEMALE', 'relationship' => 'CHILD',
        'guardian_attestation' => true, 'guardian_attestation_version' => config('mediconnect.dependents.guardian_attestation_version'),
    ], [...$headers, 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated()->json('data.id');
}

/** A second location of the world's clinic, added by its manager (the test client's cached user is cleared first). */
function wi_second_location(array $world, string $name = 'Second branch'): string
{
    app('auth')->forgetGuards();

    return add_location($world['clinic'], $world['manager'], $name);
}

function wi_post(string $path, array $headers, array $body = [], ?string $key = null): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson("/api/v1/walk-ins/{$path}", $body, [...$headers, 'Idempotency-Key' => $key ?? (string) Str::uuid7()]);
}

function wi_queue_id(): string
{
    return (string) DB::table('walkin_queues')->value('id');
}

function wi_json(string $url, array $headers): TestResponse
{
    app('auth')->forgetGuards();

    return test()->getJson($url, $headers);
}

/** The staff dedupe search: a POST with a JSON body, so the term is never in a URL. */
function wi_search(string $locationId, string $term, array $headers): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/walk-ins/search', ['clinic_location_id' => $locationId, 'term' => $term], $headers);
}

/** A weekly schedule replacing the world's Monday shift. */
function wi_schedule(array $world, array $shifts): void
{
    DB::table('doctor_schedules')->update(['status' => 'ARCHIVED']);
    app('auth')->forgetGuards();
    test()->postJson('/api/v1/schedules', ['name' => 'Replacement', 'effective_from' => CarbonImmutable::now('Asia/Manila')->toDateString(), 'status' => 'ACTIVE',
        'shifts' => array_map(fn (array $s) => ['day_of_week' => 1, 'doctor_service_mode_id' => $world['mode'], 'slot_duration_minutes' => 20, 'buffer_minutes' => 5, ...$s], $shifts)], $world['doctorHeaders'])->assertCreated();
    app('auth')->forgetGuards();
}

function wi_local(CarbonImmutable $instant): string
{
    return $instant->setTimezone('Asia/Manila')->format('H:i');
}

function wi_appointment(array $world, CarbonImmutable $starts, CarbonImmutable $ends, ?string $service = null, ?string $mode = null, ?string $location = 'world'): void
{
    $patient = PatientProfile::factory()->create();
    DB::table('appointments')->insert([
        'id' => (string) Str::uuid7(), 'reference_code' => strtoupper(Str::random(10)), 'patient_profile_id' => $patient->id, 'booked_by_user_id' => $world['doctor']->user_id, 'doctor_profile_id' => $world['doctor']->id,
        'clinic_location_id' => $location === 'world' ? $world['location'] : $location, 'doctor_service_id' => $service ?? $world['service'], 'doctor_service_mode_id' => $mode ?? $world['mode'],
        'consultation_type' => ($location === 'world' ? 'IN_PERSON' : 'ONLINE'), 'starts_at' => $starts, 'ends_at' => $ends, 'timezone' => 'Asia/Manila', 'status' => 'CONFIRMED', 'payer_type' => 'SELF_PAY',
        'fee_amount' => 65000, 'currency' => 'PHP', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function wi_hold(array $world, string $slotId, CarbonImmutable $starts, CarbonImmutable $ends, CarbonImmutable $expires): void
{
    $patient = PatientProfile::factory()->create();
    DB::table('appointment_slot_reservations')->insert([
        'id' => (string) Str::uuid7(), 'doctor_availability_slot_id' => $slotId, 'doctor_profile_id' => $world['doctor']->id, 'starts_at' => $starts, 'ends_at' => $ends,
        'patient_profile_id' => $patient->id, 'booked_by_user_id' => $world['doctor']->user_id, 'status' => 'ACTIVE', 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * A second ACTIVE doctor at the world's location with a walk-in service and a Monday shift, through the real APIs.
 *
 * @return array{doctor: DoctorProfile, headers: array<string, string>, service: string}
 */
function wi_second_doctor(array $world, string $licence = '7654321'): array
{
    [$doctor, $headers] = verified_doctor($licence, 'ACTIVE');
    assign($world['clinic'], $world['location'], $world['manager'], $headers, $licence);
    $service = test()->postJson('/api/v1/doctors/me/services', in_person($world['location'], 65000), $headers)->assertCreated()->json('data.id');
    $mode = test()->putJson("/api/v1/doctors/me/services/{$service}/mode", ['service_mode' => 'WALK_IN'], $headers)->assertOk()->json('data.service_mode_id');
    test()->postJson('/api/v1/schedules', ['name' => 'Monday walk-ins', 'effective_from' => CarbonImmutable::now('Asia/Manila')->toDateString(), 'status' => 'ACTIVE',
        'shifts' => [['day_of_week' => 1, 'doctor_service_mode_id' => $mode, 'starts_at' => '09:00', 'ends_at' => '12:00', 'slot_duration_minutes' => 20, 'buffer_minutes' => 5]]], $headers)->assertCreated();
    app('auth')->forgetGuards();

    return ['doctor' => $doctor, 'headers' => $headers, 'service' => (string) $service];
}

/** A guest the staff register (WAITING at once); returns the entry id. */
function wi_guest(array $staff, string $service, string $name = 'Guest One', array $extra = []): string
{
    return register_walkin($staff, guest_body($service, $name, $extra))->assertCreated()->json('data.id');
}

/** A guest registered with a triage level above normal. */
function wi_triaged(array $staff, string $service, string $name = 'Urgent Guest', string $level = 'URGENT'): string
{
    return wi_guest($staff, $service, $name, ['priority_level' => $level, 'priority_reason_code' => 'STAFF_CONCERN']);
}

/** Call-next on a queue (a fresh Idempotency-Key per attempt, as clients must). */
function wi_call_next(string $queue, array $headers, ?string $key = null): TestResponse
{
    return wi_post("queues/{$queue}/call-next", $headers, [], $key);
}

/** Moves the pinned clock forward. */
function wi_advance(int $minutes): void
{
    test()->clock->at = test()->clock->at->addMinutes($minutes);
}

/**
 * A second queue of the world's doctor at the world's location today (another clinic-service pool, so the day-unique index allows it), with one entry
 * straight from the database. A CALLED entry is assigned to the doctor. Returns [queue id, entry id].
 *
 * @return array{0: string, 1: string}
 */
function wi_second_queue_for_doctor(array $world, string $status = 'WAITING', string $prefix = 'B'): array
{
    $pool = (string) Str::uuid7();
    DB::table('clinic_services')->insert(['id' => $pool, 'clinic_location_id' => $world['location'], 'name' => 'Pool '.Str::random(6), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    $queue = (string) Str::uuid7();
    DB::table('walkin_queues')->insert(['id' => $queue, 'clinic_location_id' => $world['location'], 'doctor_profile_id' => $world['doctor']->id, 'clinic_service_id' => $pool, 'local_date' => test()->clock->now()->setTimezone('Asia/Manila')->toDateString(), 'status' => 'OPEN', 'number_prefix' => $prefix, 'last_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $registrant = $world['doctor']->user_id;
    // The service must belong to the queue's clinic-service pool (a trigger keeps an entry and its queue consistent).
    $service = (string) Str::uuid7();
    DB::table('doctor_services')->insert(['id' => $service, 'doctor_profile_id' => $world['doctor']->id, 'clinic_location_id' => $world['location'], 'clinic_service_id' => $pool, 'name' => 'Pool service '.Str::random(5),
        'consultation_type' => 'IN_PERSON', 'duration_minutes' => 20, 'fee_amount' => 30000, 'currency' => 'PHP', 'status' => 'ACTIVE', 'effective_from' => now(), 'created_by' => $registrant]);
    $entry = (string) Str::uuid7();
    DB::table('walkin_queue_entries')->insert([
        'id' => $entry, 'walkin_queue_id' => $queue, 'queue_number' => 1, 'guest_name_encrypted' => encrypt('Pool Guest'), 'registered_by_user_id' => $registrant, 'registration_channel' => 'STAFF_CONSOLE',
        'status' => $status, 'payer_type' => 'SELF_PAY', 'doctor_service_id' => $service, 'arrived_at' => now(), 'queue_sort_at' => $status === 'WAITING' ? now() : null,
        'assigned_doctor_profile_id' => $status === 'CALLED' ? $world['doctor']->id : null, 'called_at' => $status === 'CALLED' ? now() : null,
        'privacy_notice_version' => 'v1', 'privacy_notice_shown_by_user_id' => $registrant, 'privacy_notice_shown_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    return [$queue, $entry];
}

/** The status of an entry, straight from the database. */
function wi_status(string $id): string
{
    return (string) DB::table('walkin_queue_entries')->where('id', $id)->value('status');
}
