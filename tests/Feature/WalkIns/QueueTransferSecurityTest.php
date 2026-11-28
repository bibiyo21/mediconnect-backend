<?php

use App\Modules\Presence\Services\PresenceCheckOut;
use App\Modules\Rbac\Enums\Role;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;

/*
 * Increment 5.8-T2: transfer security (brief §4 5.8-T2, review L2). A patient, an ADMIN global reader and a clinic member restricted to another
 * location must not move an entry. Each refusal is checked by status code and by the entry staying in its queue.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    app()->when(PresenceCheckOut::class)->needs(Clock::class)->give(fn () => $this->clock);
    $this->w = walkin_world(['remote' => true]);
    $this->staff = staff_of($this->w);
    [$this->user, $this->profile, $this->headers] = remote_patient();
    doctor_in($this->w);
    $this->remote = register_walkin($this->headers, patient_body($this->w['service'], $this->profile))->assertCreated()->json('data.id');
    wi_post("{$this->remote}/arrive", $this->staff)->assertOk();
    $this->source = wi_queue_id();

    // A second doctor at the same location gives a target queue for today.
    $this->second = wi_second_doctor($this->w);
    register_walkin($this->staff, guest_body($this->second['service'], 'Waiting Guest'))->assertCreated();
    $this->target = (string) DB::table('walkin_queues')->where('doctor_profile_id', $this->second['doctor']->id)->value('id');
});

/** The entry's queue, as the database holds it. */
function qts_queue_of(string $entry): ?string
{
    return DB::table('walkin_queue_entries')->where('id', $entry)->value('walkin_queue_id');
}

it('a patient cannot transfer their own entry (404), and the entry stays in its queue', function () {
    wi_post("{$this->remote}/transfer", $this->headers, ['walkin_queue_id' => $this->target])->assertStatus(404);

    expect(qts_queue_of($this->remote))->toBe($this->source);
});

it('an ADMIN global reader cannot transfer an entry (403), and the entry stays in its queue', function () {
    $admin = session_for(account(Role::Admin));

    wi_post("{$this->remote}/transfer", $admin, ['walkin_queue_id' => $this->target])->assertStatus(403);

    expect(qts_queue_of($this->remote))->toBe($this->source);
});

it('a clinic member restricted to another location cannot transfer an entry at this location (404), and the entry stays in its queue', function () {
    $restricted = clinic_member($this->w['clinic'], Role::ClinicStaff, wi_second_location($this->w))[1];

    wi_post("{$this->remote}/transfer", $restricted, ['walkin_queue_id' => $this->target])->assertStatus(404);

    expect(qts_queue_of($this->remote))->toBe($this->source)
        ->and(DB::table('walkin_queue_events')->where('event_type', 'TRANSFERRED')->count())->toBe(0);
});

/*
 * Increment 5.8-T2, gate point 9 (cross-location transfer is refused). A transfer never moves an entry to a queue at another location of the same
 * clinic, even when the staff member may manage both locations. The refusal is 422 (the same-location rule) and the entry stays where it was.
 */

it('refuses a transfer to a queue at another location of the same clinic (422), even for staff who manage both, and the entry stays in its queue', function () {
    $location = wi_second_location($this->w);
    // The new location needs operating hours, or the queue is closed today (clinic_closed_today).
    test()->putJson("/api/v1/clinics/{$this->w['clinic']}/locations/{$location}/operating-hours", ['hours' => array_map(fn ($d) => ['day_of_week' => $d, 'opens_at' => '08:00', 'closes_at' => '17:00'], [1, 2, 3, 4, 5, 6, 7])], $this->w['manager'])->assertOk();
    app('auth')->forgetGuards();
    [$doctor, $headers] = verified_doctor('8800001', 'ACTIVE');
    assign($this->w['clinic'], $location, $this->w['manager'], $headers, '8800001');
    $service = test()->postJson('/api/v1/doctors/me/services', in_person($location, 65000), $headers)->assertCreated()->json('data.id');
    $mode = test()->putJson("/api/v1/doctors/me/services/{$service}/mode", ['service_mode' => 'WALK_IN'], $headers)->assertOk()->json('data.service_mode_id');
    test()->postJson('/api/v1/schedules', ['name' => 'Monday walk-ins', 'effective_from' => CarbonImmutable::now('Asia/Manila')->toDateString(), 'status' => 'ACTIVE',
        'shifts' => [['day_of_week' => 1, 'doctor_service_mode_id' => $mode, 'starts_at' => '09:00', 'ends_at' => '12:00', 'slot_duration_minutes' => 20, 'buffer_minutes' => 5]]], $headers)->assertCreated();
    app('auth')->forgetGuards();
    register_walkin($this->staff, guest_body($service, 'Other Location Guest'))->assertCreated();
    $otherLocationQueue = (string) DB::table('walkin_queues')->where('clinic_location_id', $location)->value('id');
    expect($otherLocationQueue)->not->toBe('');

    wi_post("{$this->remote}/transfer", $this->staff, ['walkin_queue_id' => $otherLocationQueue])
        ->assertStatus(422)->assertJsonValidationErrors(['walkin_queue_id']);

    expect(qts_queue_of($this->remote))->toBe($this->source)
        ->and(DB::table('walkin_queue_events')->where('event_type', 'TRANSFERRED')->count())->toBe(0);
});
