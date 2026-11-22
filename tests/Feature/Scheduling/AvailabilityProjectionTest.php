<?php

use App\Modules\Patients\Models\PatientProfile;
use App\Modules\Scheduling\Services\AvailabilityProjector;
use Carbon\CarbonImmutable;
use Database\Seeders\SchedulingPolicySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Increment 4.6: the availability projection. Input changes flow through the
 * outbox to a refresh (the test queue runs it at once); reads never generate
 * slots; slots in use are flagged, never dropped silently (§8.3).
 */

beforeEach(function () {
    seed_roles();
    $this->messages = fake_messages();
    $this->seed(SchedulingPolicySeeder::class);
    [$this->clinicId, $this->locationId, $this->manager] = clinic_with_manager();
    $this->putJson("/api/v1/clinics/{$this->clinicId}/locations/{$this->locationId}/operating-hours", ['hours' => array_map(fn ($d) => ['day_of_week' => $d, 'opens_at' => '08:00', 'closes_at' => '17:00'], [1, 2, 3, 4, 5, 6])], $this->manager)->assertOk();
    app('auth')->forgetGuards();
    [$this->doctor, $this->doctorHeaders] = verified_doctor('0123456', 'ACTIVE');
    assign($this->clinicId, $this->locationId, $this->manager, $this->doctorHeaders);
    $this->service = $this->postJson('/api/v1/doctors/me/services', in_person($this->locationId, 65000), $this->doctorHeaders)->assertCreated()->json('data.id');
    $this->mode = $this->putJson("/api/v1/doctors/me/services/{$this->service}/mode", ['service_mode' => 'APPOINTMENT'], $this->doctorHeaders)->json('data.service_mode_id');
    $this->monday = CarbonImmutable::now('Asia/Manila')->addDays(2)->next(CarbonImmutable::MONDAY)->toDateString();
    $this->scheduleId = $this->postJson('/api/v1/schedules', [
        'name' => 'Makati Mondays', 'effective_from' => CarbonImmutable::now('Asia/Manila')->toDateString(),
        'shifts' => [['day_of_week' => 1, 'doctor_service_mode_id' => $this->mode, 'starts_at' => '09:00', 'ends_at' => '12:00', 'slot_duration_minutes' => 20, 'buffer_minutes' => 5]],
    ], $this->doctorHeaders)->assertCreated()->json('data.id');
    app('auth')->forgetGuards();
});

function monday_slots(string $doctorId, string $monday): array
{
    return test()->getJson("/api/v1/doctors/{$doctorId}/availability?from={$monday}&to={$monday}")->assertOk()->json('data');
}

it('projects a new schedule into public, advisory availability', function () {
    $slots = test()->getJson("/api/v1/doctors/{$this->doctor->id}/availability?from={$this->monday}&to={$this->monday}")->assertOk()
        ->assertJsonPath('meta.advisory', true)->json('data');

    expect($slots)->toHaveCount(7)
        ->and($slots[0])->toMatchArray(['local_date' => $this->monday, 'timezone' => 'Asia/Manila', 'clinic_location_id' => $this->locationId, 'doctor_service_id' => $this->service, 'consultation_type' => 'IN_PERSON', 'service_name' => 'Consultation'])
        ->and(CarbonImmutable::parse($slots[0]['starts_at'])->setTimezone('Asia/Manila')->format('H:i'))->toBe('09:00')
        ->and(array_keys($slots[0]))->toBe(['slot_id', 'starts_at', 'ends_at', 'local_date', 'timezone', 'clinic_location_id', 'doctor_service_id', 'service_name', 'consultation_type']);
    // Sixty days ahead (the platform horizon) were generated.
    expect(DB::table('doctor_availability_slots')->max('local_date') >= CarbonImmutable::now('Asia/Manila')->addDays(52)->toDateString())->toBeTrue();
});

it('is idempotent: a second refresh keeps the same slots', function () {
    $before = DB::table('doctor_availability_slots')->orderBy('starts_at')->pluck('id')->all();
    $result = app(AvailabilityProjector::class)->refresh($this->doctor->id);

    expect(DB::table('doctor_availability_slots')->orderBy('starts_at')->pluck('id')->all())->toBe($before)
        ->and($result['removed'])->toBe(0)->and($result['flagged'])->toBe(0);
});

it('removes time blocked by an exception, and brings it back when the exception is cancelled', function () {
    $id = $this->postJson('/api/v1/schedule-exceptions', ['exception_type' => 'VACATION', 'starts_at' => $this->monday.'T00:00:00+08:00', 'ends_at' => $this->monday.'T23:59:00+08:00'], $this->doctorHeaders)->assertCreated()->json('data.id');
    expect(monday_slots($this->doctor->id, $this->monday))->toBe([]);

    $this->deleteJson("/api/v1/schedule-exceptions/{$id}", [], $this->doctorHeaders)->assertOk();
    expect(monday_slots($this->doctor->id, $this->monday))->toHaveCount(7);
});

it('follows clinic opening hours and the assignment', function () {
    $this->putJson("/api/v1/clinics/{$this->clinicId}/locations/{$this->locationId}/operating-hours", ['hours' => [['day_of_week' => 1, 'opens_at' => '10:00', 'closes_at' => '17:00']]], $this->manager)->assertOk();
    expect(monday_slots($this->doctor->id, $this->monday))->toHaveCount(5);

    $assignment = DB::table('doctor_clinic_assignments')->where('doctor_profile_id', $this->doctor->id)->value('id');
    $this->postJson("/api/v1/clinics/{$this->clinicId}/doctor-assignments/{$assignment}/end", ['reason' => 'Moved away'], $this->manager)->assertOk();
    expect(monday_slots($this->doctor->id, $this->monday))->toBe([])
        ->and(DB::table('doctor_availability_slots')->count())->toBe(0);
});

it('keeps slots in use when the schedule goes away: held and booked slots are flagged, the rest removed', function () {
    $slots = DB::table('doctor_availability_slots')->where('local_date', $this->monday)->orderBy('starts_at')->get();
    $patient = (string) PatientProfile::where('user_id', $this->doctor->user_id)->value('id');
    DB::table('appointment_slot_reservations')->insert(['id' => (string) Str::uuid7(), 'doctor_availability_slot_id' => $slots[0]->id, 'doctor_profile_id' => $this->doctor->id, 'starts_at' => $slots[0]->starts_at, 'ends_at' => $slots[0]->ends_at,
        'patient_profile_id' => $patient, 'booked_by_user_id' => $this->doctor->user_id, 'status' => 'ACTIVE', 'expires_at' => now()->addMinutes(10)]);
    $appointment = (string) Str::uuid7();
    DB::table('appointments')->insert(['id' => $appointment, 'reference_code' => 'MC-PROJ01', 'patient_profile_id' => $patient, 'booked_by_user_id' => $this->doctor->user_id, 'doctor_profile_id' => $this->doctor->id,
        'clinic_location_id' => $this->locationId, 'doctor_service_id' => $this->service, 'doctor_service_mode_id' => $this->mode, 'doctor_availability_slot_id' => $slots[1]->id, 'consultation_type' => 'IN_PERSON',
        'starts_at' => $slots[1]->starts_at, 'ends_at' => $slots[1]->ends_at, 'timezone' => 'Asia/Manila', 'status' => 'CONFIRMED', 'payer_type' => 'SELF_PAY', 'fee_amount' => 65000]);
    app(AvailabilityProjector::class)->refresh($this->doctor->id);
    expect(DB::table('doctor_availability_slots')->whereIn('id', [$slots[0]->id, $slots[1]->id])->orderBy('starts_at')->pluck('status')->all())->toBe(['HELD', 'BOOKED'])
        ->and(monday_slots($this->doctor->id, $this->monday))->toHaveCount(5); // held and booked are not offered

    $version = DB::table('doctor_schedules')->where('id', $this->scheduleId)->value('version');
    $this->deleteJson("/api/v1/schedules/{$this->scheduleId}", [], [...$this->doctorHeaders, 'If-Match' => (string) $version])->assertOk();

    expect(DB::table('doctor_availability_slots')->count())->toBe(2)
        ->and(DB::table('doctor_availability_slots')->pluck('status')->unique()->all())->toBe(['NEEDS_REVIEW'])
        ->and(DB::table('appointments')->where('id', $appointment)->first())->status->toBe('CONFIRMED')->needs_rescheduling->toBeTrue()
        ->and(DB::table('rescheduling_tasks')->where('appointment_id', $appointment)->value('reason'))->toBe('SCHEDULE_CHANGE');
});

it('applies the booking notice and hides inactive doctors and bad ranges', function () {
    DB::table('scheduling_policies')->insert(['id' => (string) Str::uuid7(), 'scope_type' => 'DOCTOR', 'scope_id' => $this->doctor->id, 'min_booking_notice_minutes' => 60 * 24 * 30, 'effective_from' => now()->subDay()]);
    app(AvailabilityProjector::class)->refresh($this->doctor->id); // bumps the cache generation
    expect(monday_slots($this->doctor->id, $this->monday))->toBe([]); // within 30 days' notice

    $this->getJson("/api/v1/doctors/{$this->doctor->id}/availability?from={$this->monday}&to=".CarbonImmutable::parse($this->monday)->addDays(40)->toDateString())
        ->assertStatus(422)->assertJsonPath('errors.to.0', 'Ask for at most 31 days at a time.');
    $ids = collect(range(1, 21))->map(fn () => (string) Str::uuid7())->map(fn ($id) => 'doctor_ids[]='.$id)->implode('&');
    $this->getJson("/api/v1/availability?{$ids}&date={$this->monday}")->assertStatus(422)->assertJsonPath('errors.doctor_ids.0', 'Ask for at most 20 doctors at a time.');
    $this->getJson("/api/v1/availability?doctor_ids[]={$this->doctor->id}&date={$this->monday}")->assertOk()->assertJsonPath("data.{$this->doctor->id}", []);

    DB::table('doctor_profiles')->where('id', $this->doctor->id)->update(['onboarding_status' => 'VERIFIED']);
    $this->getJson("/api/v1/doctors/{$this->doctor->id}/availability")->assertNotFound();
});

it('refreshes every scheduled doctor from the nightly command', function () {
    DB::table('doctor_availability_slots')->delete();
    $this->artisan('availability:generate --sync')->expectsOutputToContain('Refreshed 1 doctors')->assertSuccessful();
    expect(monday_slots($this->doctor->id, $this->monday))->toHaveCount(7);
});

it('answers the multi-doctor endpoint with an object keyed by doctor id, and {} when none match', function () {
    $hit = test()->getJson("/api/v1/availability?doctor_ids[]={$this->doctor->id}&date={$this->monday}")->assertOk();
    expect(array_keys($hit->json('data')))->toBe([$this->doctor->id])->and($hit->json("data.{$this->doctor->id}"))->toHaveCount(7);

    $none = test()->getJson('/api/v1/availability?doctor_ids[]='.Str::uuid()."&date={$this->monday}")->assertOk();
    expect($none->getContent())->toContain('"data":{}');
});
