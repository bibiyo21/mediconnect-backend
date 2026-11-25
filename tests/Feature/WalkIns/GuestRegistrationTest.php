<?php

use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Rbac\Enums\Role;
use Carbon\CarbonImmutable;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/*
 * Increment 5.5a: walk-in registration by staff and the doctor (guests), POST /walk-ins. The queue is created lazily on the first
 * admitted registration; numbers, capacity, cutoff, prefix, priority, HMO claim and the idempotency guards are checked through the API
 * and the database. Patient-app registration is in PatientRegistrationTest.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world();
    $this->svc = $this->w['service'];
    $this->staff = staff_of($this->w);
});

function wi_entries(): Collection
{
    return DB::table('walkin_queue_entries')->orderBy('queue_number')->get();
}

function wi_events(string $type): Collection
{
    return DB::table('walkin_queue_events')->where('event_type', $type)->orderBy('id')->get();
}

it('lets staff register a guest: lazy queue, derived capacity and cutoff, WAITING now, label, encrypted guest data', function () {
    expect(DB::table('walkin_queues')->count())->toBe(0);

    $r = register_walkin($this->staff, guest_body($this->svc))->assertCreated();

    $r->assertJsonPath('data.label', 'A-01')->assertJsonPath('data.status', 'WAITING')->assertJsonPath('data.registration_channel', 'STAFF_CONSOLE')
        ->assertJsonPath('data.name', 'Juan Dela Cruz')->assertJsonPath('data.is_guest', true)->assertJsonPath('data.fee.amount', 65000)
        ->assertJsonPath('data.timezone', 'Asia/Manila')->assertJsonPath('data.estimate.is_estimate', true);
    expect($r->json('data'))->not->toHaveKey('guest_phone');

    $queue = DB::table('walkin_queues')->sole();
    // 09:00-12:00 with 20 + 5 minute slots: floor(180 / 25) = 7. Cutoff: the last window ends 12:00 Manila, minus 30 minutes.
    expect($queue->capacity)->toBe(7)->and($queue->number_prefix)->toBe('A')->and($queue->last_number)->toBe(1)->and($queue->status)->toBe('OPEN')
        ->and(CarbonImmutable::parse($queue->cutoff_at)->setTimezone('Asia/Manila')->format('H:i'))->toBe('11:30')
        ->and(DB::table('walkin_queue_events')->where('event_type', 'QUEUE_OPENED')->count())->toBe(1);

    $entry = DB::table('walkin_queue_entries')->sole();
    expect($entry->status)->toBe('WAITING')->and($entry->arrived_at)->not->toBeNull()->and($entry->registration_channel)->toBe('STAFF_CONSOLE')
        ->and($entry->privacy_notice_version)->toBe('v1')->and($entry->privacy_notice_shown_by_user_id)->not->toBeNull()
        ->and($entry->guest_name_encrypted)->not->toContain('Juan')->and($entry->guest_phone_encrypted)->not->toContain('9171234567')
        ->and($entry->guest_phone_hash)->toHaveLength(64);

    $event = wi_events('REGISTERED')->sole();
    expect($event->to_status)->toBe('WAITING')->and($event->reason)->toBeNull()->and(json_decode($event->metadata, true))->toEqual(['registration_channel' => 'STAFF_CONSOLE', 'payer_type' => 'SELF_PAY']);
    $audit = DB::table('audit_logs')->where('action', 'walkins.registered')->sole();
    expect($audit->subject_id)->toBe($entry->id)->and($audit->changes)->not->toContain('Juan')->not->toContain('9171234567');
});

it('numbers registrations one after the other under one queue, and the label pads to two digits', function () {
    foreach (range(1, 3) as $i) {
        register_walkin($this->staff, guest_body($this->svc, "Guest Number{$i}", ['guest' => ['name' => "Guest Number{$i}"]]))->assertCreated()->assertJsonPath('data.label', 'A-0'.$i);
    }

    expect(wi_entries()->pluck('queue_number')->all())->toBe([1, 2, 3])->and(DB::table('walkin_queues')->count())->toBe(1)->and(DB::table('walkin_queues')->value('last_number'))->toBe(3);
});

it('gives each queue of a location and day its own prefix, honouring the policy prefix when it is free', function () {
    // Another doctor already has queue A at this location today.
    $other = directory_doctor('ACTIVE', 'Dr. Other', ClinicLocation::findOrFail($this->w['location']));
    DB::table('walkin_queues')->insert(['id' => (string) Str::uuid7(), 'clinic_location_id' => $this->w['location'], 'doctor_profile_id' => $other->id, 'local_date' => $this->clock->now()->setTimezone('Asia/Manila')->toDateString(), 'number_prefix' => 'A', 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now()]);

    register_walkin($this->staff, guest_body($this->svc))->assertCreated()->assertJsonPath('data.label', 'B-01');
    expect(DB::table('walkin_queues')->where('doctor_profile_id', $this->w['doctor']->id)->value('number_prefix'))->toBe('B');
});

it('uses the number prefix of the doctor policy when it is free', function () {
    walkin_policy($this->w['manager'], $this->w['clinic'], $this->w['assignment'], ['number_prefix' => 'DR'])->assertOk();

    register_walkin($this->staff, guest_body($this->svc))->assertCreated()->assertJsonPath('data.label', 'DR-01');
});

it('registers before check-in on the staff path and estimates "unknown" until the doctor is in (B2)', function () {
    register_walkin($this->staff, guest_body($this->svc))->assertCreated()->assertJsonPath('data.estimate.wait_status', 'UNKNOWN')
        ->assertJsonPath('data.estimate.min_minutes', null)->assertJsonPath('data.estimate.basis', ['doctor_not_checked_in', 'unknown']);
});

it('estimates a range once the doctor is in, and counts people already waiting', function () {
    doctor_in($this->w);
    $first = register_walkin($this->staff, guest_body($this->svc, 'First Guest'))->assertCreated();
    $second = register_walkin($this->staff, guest_body($this->svc, 'Second Guest', ['guest' => ['name' => 'Second Guest']]))->assertCreated();

    expect($first->json('data.estimate.position'))->toBe(1)->and($first->json('data.estimate.min_minutes'))->not->toBeNull()
        ->and($first->json('data.estimate.max_minutes'))->toBeGreaterThan($first->json('data.estimate.min_minutes'))
        ->and($second->json('data.estimate.position'))->toBe(2)->and($second->json('data.estimate.min_minutes'))->toBeGreaterThan($first->json('data.estimate.min_minutes'))
        ->and($second->json('data.estimate.basis'))->toContain('queue_ahead', 'duration_service');
    // The snapshot is written once for the metric and never served.
    expect(DB::table('walkin_queue_entries')->where('queue_number', 2)->value('estimated_call_at_min'))->not->toBeNull()
        ->and($second->json('data'))->not->toHaveKey('estimated_call_at_min');
});

it('refuses registration after the doctor checked out today (OUT), staff included', function () {
    doctor_in($this->w);
    DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->w['doctor']->id)->update(['current_status' => 'OUT', 'checked_out_at' => $this->clock->now()->addMinutes(1), 'checkout_reason' => 'END_OF_SHIFT', 'checked_out_method' => 'SYSTEM']);

    register_walkin($this->staff, guest_body($this->svc))->assertStatus(409)->assertJsonPath('code', 'REGISTRATION_NOT_ALLOWED')->assertJsonPath('reason_code', 'doctor_checked_out');
    expect(DB::table('walkin_queues')->count())->toBe(0);
});

it('treats a doctor who is in at another location as not checked in here, so the staff path still works', function () {
    $other = wi_second_location($this->w);
    DB::table('doctor_presence_sessions')->insert(['id' => (string) Str::uuid7(), 'doctor_profile_id' => $this->w['doctor']->id, 'clinic_location_id' => $other, 'session_kind' => 'IN_PERSON', 'current_status' => 'IN', 'checked_in_at' => $this->clock->now(), 'checked_in_by' => $this->w['doctor']->user_id, 'created_at' => now(), 'updated_at' => now()]);

    register_walkin($this->staff, guest_body($this->svc))->assertCreated();
});

it('refuses when the doctor has no walk-in hours today, and when the clinic is closed today', function () {
    $this->clock->at = next_monday_at('10:00')->addDay(); // Tuesday: the shift is on Mondays only
    register_walkin($this->staff, guest_body($this->svc))->assertStatus(409)->assertJsonPath('code', 'REGISTRATION_NOT_ALLOWED')->assertJsonPath('reason_code', 'no_walkin_hours_today');

    $this->clock->at = next_monday_at('10:00');
    $monday = $this->clock->now()->setTimezone('Asia/Manila')->toDateString();
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/clinics/{$this->w['clinic']}/holidays", ['date' => $monday, 'name' => 'Founding day', 'is_closed' => true], $this->w['manager'])->assertCreated();
    register_walkin($this->staff, guest_body($this->svc))->assertStatus(409)->assertJsonPath('code', 'QUEUE_CLOSED')->assertJsonPath('reason_code', 'clinic_closed_today');
    expect(DB::table('walkin_queues')->count())->toBe(0);
});

it('stops admissions at the cutoff (30 minutes before the last window ends) and honours the policy offset', function () {
    $this->clock->at = next_monday_at('11:45');
    register_walkin($this->staff, guest_body($this->svc))->assertStatus(409)->assertJsonPath('code', 'QUEUE_CLOSED')->assertJsonPath('reason_code', 'cutoff_passed');
    expect(DB::table('walkin_queues')->count())->toBe(0);

    walkin_policy($this->w['manager'], $this->w['clinic'], $this->w['assignment'], ['cutoff_minutes_before_close' => 5])->assertOk();
    register_walkin($this->staff, guest_body($this->svc))->assertCreated();
    expect(CarbonImmutable::parse(DB::table('walkin_queues')->value('cutoff_at'))->setTimezone('Asia/Manila')->format('H:i'))->toBe('11:55');
});

it('rederives capacity under the queue lock at the next registration and writes it back', function () {
    register_walkin($this->staff, guest_body($this->svc, 'First Guest'))->assertCreated();
    expect(DB::table('walkin_queues')->value('capacity'))->toBe(7);

    // The schedule changes during the day: the shift now has an explicit walk-in capacity of 3.
    DB::table('doctor_schedule_shifts')->update(['walkin_capacity' => 3]);
    register_walkin($this->staff, guest_body($this->svc, 'Second Guest', ['guest' => ['name' => 'Second Guest']]))->assertCreated();

    expect(DB::table('walkin_queues')->value('capacity'))->toBe(3);

    walkin_policy($this->w['manager'], $this->w['clinic'], $this->w['assignment'], ['capacity_mode' => 'UNLIMITED'])->assertOk();
    register_walkin($this->staff, guest_body($this->svc, 'Third Guest', ['guest' => ['name' => 'Third Guest']]))->assertCreated();
    expect(DB::table('walkin_queues')->value('capacity'))->toBeNull();
});

it('makes staff confirm an over-capacity registration, audits the override, and keeps cancelled places free', function () {
    DB::table('doctor_schedule_shifts')->update(['walkin_capacity' => 2]);
    $a = register_walkin($this->staff, guest_body($this->svc, 'Guest Alpha', ['guest' => ['name' => 'Guest Alpha']]))->assertCreated()->json('data.id');
    register_walkin($this->staff, guest_body($this->svc, 'Guest Bravo', ['guest' => ['name' => 'Guest Bravo']]))->assertCreated();

    $over = register_walkin($this->staff, guest_body($this->svc, 'Guest Charlie', ['guest' => ['name' => 'Guest Charlie']]))->assertStatus(409);
    $over->assertJsonPath('code', 'CAPACITY_OVERRIDE_REQUIRED')->assertJsonPath('errors.current.0', '2')->assertJsonPath('errors.capacity.0', '2');
    expect(wi_entries())->toHaveCount(2);

    $ok = register_walkin($this->staff, guest_body($this->svc, 'Guest Charlie', ['guest' => ['name' => 'Guest Charlie'], 'capacity_override' => true]))->assertCreated();
    $event = wi_events('CAPACITY_OVERRIDDEN')->sole();
    $audit = DB::table('audit_logs')->where('action', 'walkins.capacity_overridden')->sole();
    expect($event->walkin_queue_entry_id)->toBe($ok->json('data.id'))->and(json_decode($event->metadata, true))->toBe(['current' => 2, 'capacity' => 2])
        ->and($audit->actor_user_id)->not->toBeNull()->and(json_decode($audit->changes, true)['current'])->toBe(2);

    // A cancelled place is free again: with one of the three cancelled, the next registration is again over capacity (2 held + new).
    app('auth')->forgetGuards();
    $this->postJson("/api/v1/walk-ins/{$a}/cancel", ['reason_code' => 'PATIENT_LEFT', 'patient_informed_in_person' => true], [...$this->staff, 'Idempotency-Key' => (string) Str::uuid7()])->assertOk();
    register_walkin($this->staff, guest_body($this->svc, 'Guest Delta', ['guest' => ['name' => 'Guest Delta']]))->assertStatus(409)->assertJsonPath('code', 'CAPACITY_OVERRIDE_REQUIRED');
});

it('admits nobody to a queue with zero walk-in capacity unless staff override', function () {
    DB::table('doctor_schedule_shifts')->update(['walkin_capacity' => 0]);

    register_walkin($this->staff, guest_body($this->svc))->assertStatus(409)->assertJsonPath('code', 'CAPACITY_OVERRIDE_REQUIRED')->assertJsonPath('errors.capacity.0', '0');
    expect(DB::table('walkin_queues')->count())->toBe(0);
    register_walkin($this->staff, guest_body($this->svc, extra: ['capacity_override' => true]))->assertCreated();
});

it('validates the body: notice, phone, prohibited fields, exclusive paths, priority, HMO', function () {
    $post = fn (array $body) => register_walkin($this->staff, $body);
    $base = guest_body($this->svc);

    $post([...$base, 'privacy_notice_version' => null])->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED')->assertJsonValidationErrorFor('privacy_notice_version', 'errors');
    $post(guest_body($this->svc, 'Valid Name', ['guest' => ['name' => 'Valid Name', 'phone' => '12345']]))->assertStatus(422)->assertJsonPath('errors', fn ($e) => ($e['guest.phone'][0] ?? null) === 'Enter a valid Philippine mobile number.');
    $post(guest_body($this->svc, 'X'))->assertStatus(422)->assertJsonValidationErrorFor('guest.name', 'errors');
    $post([...$base, 'guest' => ['name' => 'Valid Name', 'age' => 33]])->assertStatus(422);
    $post(collect($base)->except('guest')->all())->assertStatus(422);
    $post([...$base, 'patient_profile_id' => (string) Str::uuid7()])->assertStatus(422);
    $post([...$base, 'payer_type' => 'MAYA'])->assertStatus(422);
    foreach (['hmo_membership_id' => (string) Str::uuid7(), 'clinic_service_id' => (string) Str::uuid7(), 'fee_amount' => 1, 'price' => 1, 'registration_channel' => 'KIOSK', 'queue_number' => 99, 'triage_priority' => 9, 'local_date' => '2026-01-01'] as $field => $value) {
        $post([...$base, $field => $value])->assertStatus(422)->assertJsonValidationErrorFor($field, 'errors');
    }
    $post([...$base, 'priority_level' => 'URGENT'])->assertStatus(422)->assertJsonValidationErrorFor('priority_reason_code', 'errors');
    $post([...$base, 'priority_level' => 'LOUD', 'priority_reason_code' => 'OTHER'])->assertStatus(422);
    $post([...$base, 'priority_reason_code' => 'PREGNANT'])->assertStatus(422);
    $post([...$base, 'priority_level' => 'PRIORITY', 'priority_reason_code' => 'PREGNANT', 'priority_note' => str_repeat('a', 141)])->assertStatus(422);
    $post([...$base, 'hmo_card_name_text' => 'Maxicare'])->assertStatus(422)->assertJsonValidationErrorFor('hmo_card_name_text', 'errors');
    $post([...$base, 'payer_type' => 'HMO'])->assertStatus(422)->assertJsonValidationErrorFor('hmo_card_name_text', 'errors');

    expect(DB::table('walkin_queue_entries')->count())->toBe(0);
});

it('stores priority as a code with an encrypted note that never reaches events, audit or the broadcast of the entry', function () {
    $r = register_walkin($this->staff, guest_body($this->svc, extra: ['priority_level' => 'URGENT', 'priority_reason_code' => 'PREGNANT', 'priority_note' => 'seven months along']))->assertCreated();

    $r->assertJsonPath('data.priority_level', 'URGENT')->assertJsonPath('data.priority_reason_code', 'PREGNANT');
    $entry = DB::table('walkin_queue_entries')->sole();
    expect($entry->triage_priority)->toBe(9)->and($entry->triage_reason_code)->toBe('PREGNANT')->and($entry->triage_note_encrypted)->not->toContain('seven');
    $event = wi_events('REGISTERED')->sole();
    expect(json_decode($event->metadata, true))->toMatchArray(['priority_level' => 'URGENT', 'priority_reason_code' => 'PREGNANT'])
        ->and(json_encode([$event, DB::table('audit_logs')->get()]))->not->toContain('seven months');
    expect($r->getContent())->not->toContain('seven months');
});

it('captures the HMO as the card name the patient typed, never a verified coverage', function () {
    $r = register_walkin($this->staff, guest_body($this->svc, extra: ['payer_type' => 'HMO', 'hmo_card_name_text' => 'Maxicare Gold']))->assertCreated();

    $r->assertJsonPath('data.hmo_claim.label', 'Patient says HMO: not verified')->assertJsonPath('data.hmo_claim.card_name_text', 'Maxicare Gold')->assertJsonPath('data.payer_type', 'HMO');
    $entry = DB::table('walkin_queue_entries')->sole();
    expect($entry->hmo_card_name_encrypted)->not->toContain('Maxicare')->and($entry->hmo_membership_id)->toBeNull()
        ->and(strtolower($r->getContent()))->not->toContain('covered')->not->toContain('accredited');
});

it('requires an Idempotency-Key, and replays the same request exactly once', function () {
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/walk-ins', guest_body($this->svc), $this->staff)->assertStatus(400)->assertJsonPath('code', 'BAD_REQUEST');

    $key = (string) Str::uuid7();
    $first = register_walkin($this->staff, guest_body($this->svc), $key)->assertCreated();
    $again = register_walkin($this->staff, guest_body($this->svc), $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');

    expect($again->json('data.id'))->toBe($first->json('data.id'))->and(wi_entries())->toHaveCount(1);
    register_walkin($this->staff, guest_body($this->svc, 'Somebody Else', ['guest' => ['name' => 'Somebody Else']]), $key)->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
});

it('still returns the first entry when the key row is gone, after comparing the body (decision 99)', function () {
    $key = (string) Str::uuid7();
    $first = register_walkin($this->staff, guest_body($this->svc), $key)->assertCreated();

    DB::table('idempotency_keys')->delete();
    $same = register_walkin($this->staff, guest_body($this->svc), $key)->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
    expect($same->json('data.id'))->toBe($first->json('data.id'))->and(wi_entries())->toHaveCount(1);

    DB::table('idempotency_keys')->delete();
    register_walkin($this->staff, guest_body($this->svc, 'Somebody Else', ['guest' => ['name' => 'Somebody Else']]), $key)->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    expect(wi_entries())->toHaveCount(1);
});

it('refuses a service that is not offered for walk-ins here, and an unknown one', function () {
    DB::table('doctor_service_modes')->update(['service_mode' => 'APPOINTMENT']);
    register_walkin($this->staff, guest_body($this->svc))->assertStatus(422)->assertJsonValidationErrorFor('doctor_service_id', 'errors');
    register_walkin($this->staff, guest_body((string) Str::uuid7()))->assertStatus(422)->assertJsonValidationErrorFor('doctor_service_id', 'errors');
    DB::table('doctor_service_modes')->update(['service_mode' => 'BOTH', 'status' => 'INACTIVE']);
    register_walkin($this->staff, guest_body($this->svc))->assertStatus(422);
    expect(DB::table('walkin_queues')->count())->toBe(0);
});

it('lets the doctor register a guest on their own queue once DOCTOR holds walkins.register (A2: ungranted today)', function () {
    // As built, DOCTOR does not hold the permission: the path exists and refuses.
    register_walkin($this->w['doctorHeaders'], guest_body($this->svc))->assertForbidden();

    RoleModel::findByName('DOCTOR', 'web')->givePermissionTo('walkins.register');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $r = register_walkin($this->w['doctorHeaders'], guest_body($this->svc))->assertCreated()->assertJsonPath('data.registration_channel', 'DOCTOR_APP')->assertJsonPath('data.status', 'WAITING');
    expect(wi_entries()->sole()->registration_channel)->toBe('DOCTOR_APP');

    // Another doctor cannot register on this doctor's queue.
    [, $otherHeaders] = verified_doctor('7654321', 'ACTIVE');
    register_walkin($otherHeaders, guest_body($this->svc))->assertForbidden();
});

it('refuses everyone without staff rights at the location', function () {
    $body = guest_body($this->svc);

    // Unrelated clinic staff and manager.
    $other = walkin_world(['clinic' => 'Reyes Clinic', 'licence' => '1111111']);
    register_walkin($other['manager'], $body)->assertForbidden();
    register_walkin(staff_of($other), $body)->assertForbidden();
    // Staff restricted to another location of this clinic.
    $second = wi_second_location($this->w);
    register_walkin(staff_of($this->w, Role::ClinicStaff, $second), $body)->assertForbidden();
    register_walkin(staff_of($this->w, Role::ClinicManager, $second), $body)->assertForbidden();
    // Roles without walk-in registration, and the patient on the guest path.
    foreach ([Role::FinanceOfficer, Role::SupportAgent, Role::Moderator, Role::Admin, Role::MedicalAdmin, Role::SuperAdmin] as $role) {
        register_walkin(session_for(account($role)), $body)->assertForbidden();
    }
    [, , $patientHeaders] = remote_patient();
    register_walkin($patientHeaders, $body)->assertForbidden();
    // A removed member.
    register_walkin(clinic_member($this->w['clinic'], Role::ClinicStaff, null, 'REMOVED')[1], $body)->assertForbidden();

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/walk-ins', $body, ['Idempotency-Key' => (string) Str::uuid7()])->assertUnauthorized();
    expect(DB::table('walkin_queue_entries')->count())->toBe(0);
});

it('lets staff restricted to this location and clinic managers register', function () {
    register_walkin(staff_of($this->w, Role::ClinicStaff, $this->w['location']), guest_body($this->svc))->assertCreated();
    register_walkin($this->w['manager'], guest_body($this->svc, 'Second Guest', ['guest' => ['name' => 'Second Guest']]))->assertCreated();
});

it('does not echo a guest name or phone in a validation or conflict body', function () {
    DB::table('doctor_schedule_shifts')->update(['walkin_capacity' => 0]);
    $body = guest_body($this->svc, 'Zelda Quirke', ['guest' => ['name' => 'Zelda Quirke', 'phone' => '09179998888'], 'hmo_membership_id' => (string) Str::uuid7()]);

    $r = register_walkin($this->staff, $body)->assertStatus(422);
    $c = register_walkin($this->staff, guest_body($this->svc, 'Zelda Quirke', ['guest' => ['name' => 'Zelda Quirke', 'phone' => '09179998888']]))->assertStatus(409);
    expect($r->getContent().$c->getContent())->not->toContain('Zelda')->not->toContain('9179998888')->not->toContain('+63917');
});

it('never lets a stranger read a pending guest: no row is created by a refused registration', function () {
    register_walkin($this->staff, guest_body($this->svc, extra: ['fee_amount' => 0]))->assertStatus(422);

    expect(DB::table('walkin_queues')->count())->toBe(0)->and(DB::table('walkin_queue_entries')->count())->toBe(0)->and(DB::table('walkin_queue_events')->count())->toBe(0);
});
