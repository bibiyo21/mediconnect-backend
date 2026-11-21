<?php

use App\Modules\Clinics\Models\ClinicMember;
use App\Modules\Rbac\Contracts\AssignsRoles;
use App\Modules\Rbac\Enums\Role;
use Database\Seeders\SchedulingPolicySeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * Increment 4.3: recurring weekly schedules with shifts and breaks (§7.2),
 * validated, optimistically locked (scenario 4 groundwork), clinic-scoped,
 * and a public summary without internal fields.
 */

beforeEach(function () {
    seed_roles();
    $this->messages = fake_messages();
    $this->seed(SchedulingPolicySeeder::class);
    [$this->clinicId, $this->locationId, $this->manager] = clinic_with_manager();
    [$this->doctor, $this->doctorHeaders] = verified_doctor('0123456', 'ACTIVE');
    assign($this->clinicId, $this->locationId, $this->manager, $this->doctorHeaders);
    $inPerson = $this->postJson('/api/v1/doctors/me/services', in_person($this->locationId, 65000), $this->doctorHeaders)->assertCreated()->json('data.id');
    $online = $this->postJson('/api/v1/doctors/me/services', ['consultation_type' => 'ONLINE', 'name' => 'Teleconsultation', 'duration_minutes' => 15, 'fee_amount' => 50000], $this->doctorHeaders)->assertCreated()->json('data.id');
    $this->inPersonMode = $this->putJson("/api/v1/doctors/me/services/{$inPerson}/mode", ['service_mode' => 'BOTH'], $this->doctorHeaders)->json('data.service_mode_id');
    $this->onlineMode = $this->putJson("/api/v1/doctors/me/services/{$online}/mode", ['service_mode' => 'APPOINTMENT'], $this->doctorHeaders)->json('data.service_mode_id');
    app('auth')->forgetGuards();
});

/** The first message for a field whose key contains dots (shifts.0.breaks.0), which assertJsonPath cannot address. */
function field_error(TestResponse $response, string $field): ?string
{
    $response->assertStatus(422);

    return $response->json('errors')[$field][0] ?? null;
}

function weekday_schedule(string $modeId, array $overrides = [], array $shift = []): array
{
    $base = ['day_of_week' => 1, 'doctor_service_mode_id' => $modeId, 'starts_at' => '09:00', 'ends_at' => '12:00', 'slot_duration_minutes' => 20, 'buffer_minutes' => 5, 'max_appointments' => 6, 'walkin_capacity' => 4, 'breaks' => [['starts_at' => '10:30', 'ends_at' => '10:45', 'label' => 'Coffee']]];

    return ['name' => 'Makati mornings', 'effective_from' => '2026-12-01', 'status' => 'ACTIVE', 'shifts' => [array_merge($base, $shift), array_merge($base, $shift, ['day_of_week' => 3])], ...$overrides];
}

it('creates a schedule with shifts and breaks in the location time zone', function () {
    $data = $this->postJson('/api/v1/schedules', weekday_schedule($this->inPersonMode), $this->doctorHeaders)->assertCreated()->json('data');

    expect($data['version'])->toBe(1)->and($data['status'])->toBe('ACTIVE')->and($data['shifts'])->toHaveCount(2)
        ->and($data['shifts'][0])->toMatchArray(['day_of_week' => 1, 'starts_at' => '09:00', 'ends_at' => '12:00', 'timezone' => 'Asia/Manila', 'clinic_location_id' => $this->locationId])
        ->and($data['shifts'][0]['breaks'][0])->toBe(['starts_at' => '10:30', 'ends_at' => '10:45', 'label' => 'Coffee'])
        ->and(DB::table('outbox_events')->where('event_type', 'scheduling.inputs_changed')->where('aggregate_id', $this->doctor->id)->exists())->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'scheduling.schedule_created')->exists())->toBeTrue();

    expect($this->getJson('/api/v1/schedules', $this->doctorHeaders)->assertOk()->json('data'))->toHaveCount(1);
});

it('validates shifts and breaks with plain messages', function (array $shift, string $field, string $message) {
    expect(field_error($this->postJson('/api/v1/schedules', weekday_schedule($this->inPersonMode, [], $shift), $this->doctorHeaders), $field))->toBe($message);
})->with([
    'break outside the shift' => [['breaks' => [['starts_at' => '11:50', 'ends_at' => '12:30']]], 'shifts.0.breaks.0', 'A break must be inside its shift.'],
    'slot longer than the shift' => [['ends_at' => '09:15'], 'shifts.0.slot_duration_minutes', 'The appointment length is longer than the shift.'],
    'shift past midnight' => [['starts_at' => '22:00', 'ends_at' => '02:00'], 'shifts.0.ends_at', 'A shift must end after it starts. Split a shift that runs past midnight into two.'],
]);

it('rejects overlapping shifts, mixed places and options the mode does not allow', function () {
    $overlap = weekday_schedule($this->inPersonMode);
    $overlap['shifts'][1]['day_of_week'] = 1;
    $overlap['shifts'][1]['starts_at'] = '11:00';
    $overlap['shifts'][1]['ends_at'] = '13:00';
    expect(field_error($this->postJson('/api/v1/schedules', $overlap, $this->doctorHeaders), 'shifts.1'))->toBe('This shift overlaps another shift on the same day.');

    $mixed = weekday_schedule($this->inPersonMode);
    $mixed['shifts'][1] = ['day_of_week' => 5, 'doctor_service_mode_id' => $this->onlineMode, 'starts_at' => '19:00', 'ends_at' => '21:00', 'slot_duration_minutes' => 15];
    $this->postJson('/api/v1/schedules', $mixed, $this->doctorHeaders)->assertStatus(422)->assertJsonPath('errors.shifts.0', 'A schedule covers one clinic location, or online only. Create one schedule per place.');

    $onlineWalkIns = ['name' => 'Evenings online', 'effective_from' => '2026-12-01', 'shifts' => [['day_of_week' => 2, 'doctor_service_mode_id' => $this->onlineMode, 'starts_at' => '19:00', 'ends_at' => '21:00', 'slot_duration_minutes' => 15, 'walkin_capacity' => 3]]];
    expect(field_error($this->postJson('/api/v1/schedules', $onlineWalkIns, $this->doctorHeaders), 'shifts.0.walkin_capacity'))->toBe('This service is by appointment only, so it takes no walk-ins.');
});

it('keeps a doctor in one place at a time across schedules; drafts do not count', function () {
    $this->postJson('/api/v1/schedules', weekday_schedule($this->inPersonMode), $this->doctorHeaders)->assertCreated();
    $online = ['name' => 'Online Monday', 'effective_from' => '2026-12-10', 'shifts' => [['day_of_week' => 1, 'doctor_service_mode_id' => $this->onlineMode, 'starts_at' => '11:00', 'ends_at' => '13:00', 'slot_duration_minutes' => 15]]];

    $this->postJson('/api/v1/schedules', $online, $this->doctorHeaders)->assertStatus(422)->assertJsonPath('errors.shifts.0', 'This overlaps the doctor\'s schedule "Makati mornings". A doctor can be in one place at a time.');
    $this->postJson('/api/v1/schedules', [...$online, 'status' => 'DRAFT'], $this->doctorHeaders)->assertCreated();
    $online['shifts'][0]['starts_at'] = '12:00';
    $this->postJson('/api/v1/schedules', $online, $this->doctorHeaders)->assertCreated(); // back to back is fine
});

it('changes a schedule only at the version last read', function () {
    $id = $this->postJson('/api/v1/schedules', weekday_schedule($this->inPersonMode), $this->doctorHeaders)->json('data.id');

    $this->patchJson("/api/v1/schedules/{$id}", ['name' => 'No version'], $this->doctorHeaders)->assertStatus(422)->assertJsonPath('errors.version.0', 'Send the version you last read, in the If-Match header.');
    $changed = $this->patchJson("/api/v1/schedules/{$id}", ['name' => 'Makati weekdays', 'shifts' => [weekday_schedule($this->inPersonMode)['shifts'][0]]], [...$this->doctorHeaders, 'If-Match' => '"1"'])->assertOk()->json('data');
    expect($changed['version'])->toBe(2)->and($changed['name'])->toBe('Makati weekdays')->and($changed['shifts'])->toHaveCount(1);

    // A second editor still holding version 1 is refused, and nothing changes.
    $this->patchJson("/api/v1/schedules/{$id}", ['name' => 'Stale edit'], [...$this->doctorHeaders, 'If-Match' => '1'])->assertStatus(409)->assertJsonPath('code', 'CONCURRENT_MODIFICATION');
    expect(DB::table('doctor_schedules')->where('id', $id)->value('name'))->toBe('Makati weekdays');
});

it('archives a schedule, lists affected appointments, and refuses later edits', function () {
    $id = $this->postJson('/api/v1/schedules', weekday_schedule($this->inPersonMode), $this->doctorHeaders)->json('data.id');

    $this->deleteJson("/api/v1/schedules/{$id}", [], [...$this->doctorHeaders, 'If-Match' => '1'])->assertOk()
        ->assertJsonPath('data.status', 'ARCHIVED')->assertJsonPath('data.affected_appointments', []);
    $this->patchJson("/api/v1/schedules/{$id}", ['name' => 'Again'], [...$this->doctorHeaders, 'If-Match' => '2'])->assertStatus(422);
    expect(DB::table('doctor_schedule_shifts')->count())->toBe(2); // archived, not deleted
});

it('lets the clinic manage schedules at its location only; staff read; online stays with the doctor', function () {
    $payload = [...weekday_schedule($this->inPersonMode), 'doctor_profile_id' => $this->doctor->id];
    $id = $this->postJson('/api/v1/schedules', $payload, $this->manager)->assertCreated()->json('data.id');
    expect(DB::table('audit_logs')->where('action', 'scheduling.schedule_created')->value('clinic_id'))->toBe($this->clinicId);
    $this->getJson("/api/v1/schedules?clinic_id={$this->clinicId}", $this->manager)->assertOk()->assertJsonCount(1, 'data');
    $online = ['doctor_profile_id' => $this->doctor->id, 'name' => 'Online', 'effective_from' => '2026-12-01', 'shifts' => [['day_of_week' => 6, 'doctor_service_mode_id' => $this->onlineMode, 'starts_at' => '09:00', 'ends_at' => '10:00', 'slot_duration_minutes' => 15]]];
    $this->postJson('/api/v1/schedules', $online, $this->manager)->assertForbidden();
    app('auth')->forgetGuards();

    [$otherClinic, , $otherManager] = clinic_with_manager('Diliman Medical Clinic');
    $this->getJson("/api/v1/schedules/{$id}", $otherManager)->assertForbidden();
    $this->patchJson("/api/v1/schedules/{$id}", ['name' => 'Taken over'], [...$otherManager, 'If-Match' => '1'])->assertForbidden();
    $this->getJson("/api/v1/schedules?clinic_id={$this->clinicId}", $otherManager)->assertForbidden();
    app('auth')->forgetGuards();

    $staff = account();
    app(AssignsRoles::class)->assign($staff, Role::ClinicStaff);
    (new ClinicMember)->forceFill(['clinic_id' => $this->clinicId, 'user_id' => $staff->id, 'member_role' => 'CLINIC_STAFF', 'status' => 'ACTIVE', 'joined_at' => now()])->save();
    $staffHeaders = session_for($staff->refresh());
    $this->getJson("/api/v1/schedules/{$id}", $staffHeaders)->assertOk();
    $this->patchJson("/api/v1/schedules/{$id}", ['name' => 'Staff edit'], [...$staffHeaders, 'If-Match' => '1'])->assertForbidden();
});

it('publishes regular hours without internal fields, for active doctors only', function () {
    $this->postJson('/api/v1/schedules', weekday_schedule($this->inPersonMode), $this->doctorHeaders)->assertCreated();
    $this->postJson('/api/v1/schedules', ['name' => 'Draft', 'effective_from' => '2026-12-01', 'status' => 'DRAFT', 'shifts' => [['day_of_week' => 6, 'doctor_service_mode_id' => $this->onlineMode, 'starts_at' => '09:00', 'ends_at' => '10:00', 'slot_duration_minutes' => 15]]], $this->doctorHeaders)->assertCreated();
    app('auth')->forgetGuards();

    $places = $this->getJson("/api/v1/doctors/{$this->doctor->id}/schedules")->assertOk()->json('data');
    expect($places)->toHaveCount(1)
        ->and($places[0]['clinic_name'])->toBe('Santos Family Clinic')
        ->and(collect($places[0]['hours'])->pluck('day_of_week')->all())->toBe([1, 3])
        ->and(array_keys($places[0]['hours'][0]))->toBe(['day_of_week', 'starts_at', 'ends_at', 'service_mode', 'from'])
        ->and(json_encode($places))->not->toContain('max_appointments')->not->toContain('walkin_capacity')->not->toContain('buffer');

    DB::table('doctor_profiles')->where('id', $this->doctor->id)->update(['onboarding_status' => 'VERIFIED']);
    $this->getJson("/api/v1/doctors/{$this->doctor->id}/schedules")->assertNotFound();
});
