<?php

use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Scheduling\Services\SlotHolds;
use Carbon\CarbonImmutable;
use Database\Seeders\SchedulingPolicySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Increment 4.7: temporary holds (architecture.md §8.3). Exactly one patient
 * holds a time; overlapping holds of one doctor are refused by the exclusion
 * constraint; a stale projection is caught by revalidation; expired holds
 * free the time. API only until Phase 6 booking (plan Q5).
 */

beforeEach(function () {
    seed_roles();
    $this->messages = fake_messages();
    $this->seed(SchedulingPolicySeeder::class);
    [$this->clinicId, $this->locationId, $manager] = clinic_with_manager();
    $this->putJson("/api/v1/clinics/{$this->clinicId}/locations/{$this->locationId}/operating-hours", ['hours' => array_map(fn ($d) => ['day_of_week' => $d, 'opens_at' => '08:00', 'closes_at' => '17:00'], [1, 2, 3, 4, 5, 6])], $manager)->assertOk();
    app('auth')->forgetGuards();
    [$this->doctor, $doctorHeaders] = verified_doctor('0123456', 'ACTIVE');
    assign($this->clinicId, $this->locationId, $manager, $doctorHeaders);
    $service = $this->postJson('/api/v1/doctors/me/services', in_person($this->locationId, 65000), $doctorHeaders)->json('data.id');
    $this->mode = $this->putJson("/api/v1/doctors/me/services/{$service}/mode", ['service_mode' => 'APPOINTMENT'], $doctorHeaders)->json('data.service_mode_id');
    $online = $this->postJson('/api/v1/doctors/me/services', ['consultation_type' => 'ONLINE', 'name' => 'Teleconsultation', 'duration_minutes' => 30, 'buffer_minutes' => 0, 'fee_amount' => 50000], $doctorHeaders)->json('data.id');
    $this->onlineMode = $this->putJson("/api/v1/doctors/me/services/{$online}/mode", ['service_mode' => 'APPOINTMENT'], $doctorHeaders)->json('data.service_mode_id');
    $this->monday = CarbonImmutable::now('Asia/Manila')->addDays(2)->next(CarbonImmutable::MONDAY)->toDateString();
    $this->postJson('/api/v1/schedules', ['name' => 'Makati Mondays', 'effective_from' => CarbonImmutable::now('Asia/Manila')->toDateString(),
        'shifts' => [['day_of_week' => 1, 'doctor_service_mode_id' => $this->mode, 'starts_at' => '09:00', 'ends_at' => '12:00', 'slot_duration_minutes' => 20, 'buffer_minutes' => 5]]], $doctorHeaders)->assertCreated();
    // Online special hours at the same time, so two different slots of this doctor overlap (scenario 11).
    $this->postJson('/api/v1/schedule-exceptions', ['exception_type' => 'SPECIAL_HOURS', 'doctor_service_mode_id' => $this->onlineMode,
        'starts_at' => $this->monday.'T09:00:00+08:00', 'ends_at' => $this->monday.'T10:00:00+08:00'], $doctorHeaders)->assertCreated();
    app('auth')->forgetGuards();
    $this->slots = DB::table('doctor_availability_slots')->where('local_date', $this->monday)->where('doctor_service_mode_id', $this->mode)->orderBy('starts_at')->pluck('id')->all();
    $this->onlineSlot = DB::table('doctor_availability_slots')->where('local_date', $this->monday)->where('doctor_service_mode_id', $this->onlineMode)->orderBy('starts_at')->value('id');
    $this->patient = patient_account();
    $this->headers = session_for($this->patient);
});

function hold(array $headers, string $slotId, array $extra = [], ?string $key = null): TestResponse
{
    return test()->postJson('/api/v1/availability/holds', ['slot_id' => $slotId, ...$extra], [...$headers, 'Idempotency-Key' => $key ?? (string) Str::uuid7()]);
}

it('holds a time for ten minutes, takes it out of availability, and replays the same key', function () {
    $key = (string) Str::uuid7();
    $data = hold($this->headers, $this->slots[0], key: $key)->assertCreated()->json('data');

    expect(CarbonImmutable::parse($data['expires_at'])->diffInSeconds(now()->addMinutes(10)) < 5)->toBeTrue()
        ->and(DB::table('doctor_availability_slots')->where('id', $this->slots[0])->value('status'))->toBe('HELD')
        ->and(collect($this->getJson("/api/v1/doctors/{$this->doctor->id}/availability?from={$this->monday}&to={$this->monday}")->json('data'))->pluck('slot_id'))->not->toContain($this->slots[0]);
    expect(hold($this->headers, $this->slots[0], key: $key)->assertCreated()->json('data.reservation_id'))->toBe($data['reservation_id']);
    $this->postJson('/api/v1/availability/holds', ['slot_id' => $this->slots[1]], $this->headers)->assertStatus(400);
});

it('lets only one patient hold a time, and refuses an overlapping slot of the same doctor', function () {
    hold($this->headers, $this->slots[0])->assertCreated();
    app('auth')->forgetGuards();
    $other = session_for(patient_account());

    hold($other, $this->slots[0])->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
    // The online slot overlaps 09:00-09:20 in person: a different slot id, refused by the exclusion constraint.
    hold($other, $this->onlineSlot)->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
    hold($other, $this->slots[4])->assertCreated(); // 10:40, clear of both
    expect(DB::table('appointment_slot_reservations')->where('status', 'ACTIVE')->count())->toBe(2);
});

it('frees the time when a hold expires, even before the expiry job runs', function () {
    hold($this->headers, $this->slots[0])->assertCreated();
    $this->travel(11)->minutes();
    app('auth')->forgetGuards();
    $other = session_for(patient_account());

    hold($other, $this->slots[0])->assertCreated();
    expect(DB::table('appointment_slot_reservations')->orderBy('created_at')->pluck('status')->all())->toBe(['EXPIRED', 'ACTIVE']);

    $this->travel(11)->minutes();
    expect(app(SlotHolds::class)->expireDue())->toBe(1)
        ->and(DB::table('doctor_availability_slots')->where('id', $this->slots[0])->value('status'))->toBe('OPEN');
});

it('releases only your own hold', function () {
    $id = hold($this->headers, $this->slots[0])->json('data.reservation_id');
    app('auth')->forgetGuards();
    $this->deleteJson("/api/v1/availability/holds/{$id}", [], session_for(patient_account()))->assertNotFound();
    app('auth')->forgetGuards();

    $this->deleteJson("/api/v1/availability/holds/{$id}", [], $this->headers)->assertOk()->assertJsonPath('data.status', 'RELEASED');
    expect(DB::table('doctor_availability_slots')->where('id', $this->slots[0])->value('status'))->toBe('OPEN');
});

it('allows two live holds per patient', function () {
    hold($this->headers, $this->slots[0])->assertCreated();
    hold($this->headers, $this->slots[2])->assertCreated();
    hold($this->headers, $this->slots[4])->assertStatus(422)->assertJsonPath('errors.slot_id.0', 'You already have 2 times on hold. Release one or let it expire first.');
});

it('holds for an authorized dependant only', function () {
    $childId = $this->postJson('/api/v1/patients/me/dependents', ['first_name' => 'Lia', 'last_name' => 'Santos', 'date_of_birth' => '2020-01-01', 'sex' => 'FEMALE', 'relationship' => 'CHILD',
        'guardian_attestation' => true, 'guardian_attestation_version' => config('mediconnect.dependents.guardian_attestation_version')], [...$this->headers, 'Idempotency-Key' => (string) Str::uuid7()])->assertCreated()->json('data.id');
    $stranger = PatientProfile::factory()->create(['user_id' => null, 'first_name' => 'Noel', 'last_name' => 'Reyes', 'date_of_birth' => '1980-01-01']);

    hold($this->headers, $this->slots[0], ['patient_profile_id' => $childId])->assertCreated();
    hold($this->headers, $this->slots[2], ['patient_profile_id' => $stranger->id])->assertForbidden();
    expect(DB::table('appointment_slot_reservations')->value('patient_profile_id'))->toBe($childId);
});

it('catches a stale projection and times inside the booking notice (revalidation)', function () {
    // An exception written straight to the table: the projection has not been refreshed.
    DB::table('doctor_schedule_exceptions')->insert(['id' => (string) Str::uuid7(), 'doctor_profile_id' => $this->doctor->id, 'exception_type' => 'SICK_LEAVE', 'effect' => 'BLOCK', 'priority' => 2,
        'starts_at' => $this->monday.' 00:00:00+08', 'ends_at' => $this->monday.' 23:59:00+08', 'requested_by' => $this->doctor->user_id, 'status' => 'ACTIVE']);
    hold($this->headers, $this->slots[0])->assertStatus(409)->assertJsonPath('message', 'This time was just changed by the clinic. Please choose another.');
    DB::table('doctor_schedule_exceptions')->delete();

    $this->travelTo(CarbonImmutable::parse($this->monday.' 08:30', 'Asia/Manila'));
    hold($this->headers, $this->slots[0])->assertStatus(409)->assertJsonPath('message', 'This time can no longer be reserved. Please choose a later time.');
});

it('keeps clinic staff and other roles without the permission out', function () {
    app('auth')->forgetGuards();
    $staff = account(Role::FinanceOfficer);
    // FINANCE_OFFICER is privileged (MFA); a full session still lacks appointments.create.
    hold(bearer(enroll_mfa($staff)[2]), $this->slots[0])->assertForbidden();
});
