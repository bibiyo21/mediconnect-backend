<?php

use App\Modules\Authentication\Models\PersonalAccessToken;
use App\Modules\Notifications\Realtime\RealtimeEvent;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Presence\Events\DoctorBreakEnded;
use App\Modules\Presence\Events\DoctorBreakStarted;
use App\Modules\Presence\Events\DoctorCheckedIn;
use App\Modules\Presence\Events\DoctorPresenceUpdated;
use App\Modules\Presence\Jobs\SendBreakReminder;
use App\Modules\Presence\Services\PresenceCache;
use App\Modules\Rbac\Enums\Role;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/*
 * Increment 5.3: clinic-location presence (architecture.md §9, api-specification.md §2.7).
 * Check-in, breaks and the available/unavailable toggle through the API, with the CLAUDE.md §8.5
 * event fields, audit rows, the Redis hint and the check-in rules.
 */

beforeEach(function () {
    seed_roles();
    fake_messages();
    $this->clock = pin_clock();
    $this->w = presence_world();
    $this->loc = $this->w['location'];
    $this->doc = $this->w['doctor'];
    $this->headers = $this->w['doctorHeaders'];
});

it('presence exit test: check in, break, back, unavailable, available', function () {
    checkin($this->headers, $this->loc)->assertCreated()
        ->assertJsonPath('data.status', 'IN')->assertJsonPath('data.clinic_location_id', $this->loc)->assertJsonPath('data.version', 1)->assertJsonPath('data.break_expected_end_at', null);

    $break = presence_post('break/start', $this->headers, ['expected_minutes' => 15])->assertOk()
        ->assertJsonPath('data.status', 'ON_BREAK')->assertJsonPath('data.version', 2);
    expect($break->json('data.break_expected_end_at'))->toBe($this->clock->now()->addMinutes(15)->toIso8601ZuluString());

    presence_post('break/end', $this->headers)->assertOk()->assertJsonPath('data.status', 'IN')->assertJsonPath('data.break_expected_end_at', null)->assertJsonPath('data.version', 3);
    presence_post('unavailable', $this->headers, ['reason' => 'PHONE_CALL'])->assertOk()->assertJsonPath('data.status', 'TEMPORARILY_UNAVAILABLE');
    presence_post('available', $this->headers)->assertOk()->assertJsonPath('data.status', 'IN')->assertJsonPath('data.version', 5);

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/presence/me', $this->headers)->assertOk()->assertJsonPath('data.status', 'IN')->assertJsonPath('data.version', 5);

    expect(events_of($this->doc->id)->map(fn ($e) => $e->previous_status.'>'.$e->new_status)->all())
        ->toBe(['NOT_CHECKED_IN>IN', 'IN>ON_BREAK', 'ON_BREAK>IN', 'IN>TEMPORARILY_UNAVAILABLE', 'TEMPORARILY_UNAVAILABLE>IN']);
});

it('presence/me says NOT_CHECKED_IN before a check-in and refuses a user without a doctor profile', function () {
    $this->getJson('/api/v1/presence/me', $this->headers)->assertOk()->assertJsonPath('data.status', 'NOT_CHECKED_IN')->assertJsonPath('data.session_id', null);
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/presence/me', session_for(patient_account()))->assertForbidden();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/presence/me')->assertUnauthorized();
});

it('writes every CLAUDE.md §8.5 field on each event row, with the device from the session', function () {
    checkin($this->headers, $this->loc, ['reason' => 'SHIFT_START'])->assertCreated();
    presence_post('break/start', $this->headers, ['expected_minutes' => 20])->assertOk();

    $deviceId = PersonalAccessToken::findToken(Str::after($this->headers['Authorization'], 'Bearer '))->user_device_id;
    $session = DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doc->id)->first();
    [$in, $break] = events_of($this->doc->id)->all();

    expect($deviceId)->not->toBeNull()
        ->and($in->doctor_presence_session_id)->toBe($session->id)
        ->and($in->doctor_profile_id)->toBe($this->doc->id)
        ->and($in->clinic_location_id)->toBe($this->loc)
        ->and($in->previous_status)->toBe('NOT_CHECKED_IN')->and($in->new_status)->toBe('IN')
        ->and($in->changed_by)->toBe($this->doc->user_id)
        ->and($in->change_method)->toBe('DOCTOR_APP')
        ->and($in->reason)->toBe('SHIFT_START')
        ->and($in->source_device_id)->toBe($deviceId)
        ->and($in->occurred_at)->not->toBeNull()
        ->and($break->previous_status)->toBe('IN')->and($break->new_status)->toBe('ON_BREAK')
        ->and(json_decode($break->metadata, true))->toBe(['break_kind' => 'UNSCHEDULED', 'expected_minutes' => 20, 'scheduled_break_id' => null])
        ->and($session->checked_in_by)->toBe($this->doc->user_id)
        ->and($session->session_kind)->toBe('IN_PERSON');
});

it('audits each change without the reason code', function () {
    checkin($this->headers, $this->loc, ['reason' => 'LATE_ARRIVAL'])->assertCreated();
    presence_post('unavailable', $this->headers, ['reason' => 'PERSONAL'])->assertOk();

    $rows = DB::table('audit_logs')->whereIn('action', ['presence.checked_in', 'presence.marked_unavailable'])->orderBy('id')->get();
    expect($rows->pluck('action')->all())->toBe(['presence.checked_in', 'presence.marked_unavailable'])
        ->and($rows[0]->actor_user_id)->toBe($this->doc->user_id)
        ->and($rows[0]->subject_type)->toBe('doctor_presence_session')
        ->and($rows[0]->clinic_id)->toBe($this->w['clinic'])
        ->and(json_decode($rows[1]->changes, true))->toMatchArray(['from' => 'IN', 'to' => 'TEMPORARILY_UNAVAILABLE', 'method' => 'DOCTOR_APP', 'on_behalf' => false])
        ->and(json_encode($rows->all()))->not->toContain('PERSONAL')->not->toContain('LATE_ARRIVAL');
});

it('rewrites the Redis hint presence:{doctor}:{location} after each change', function () {
    $key = PresenceCache::key($this->doc->id, $this->loc);
    checkin($this->headers, $this->loc)->assertCreated();
    $sessionId = DB::table('doctor_presence_sessions')->value('id');
    expect(Cache::get($key))->toBe(['status' => 'IN', 'version' => 1, 'session_id' => $sessionId]);

    presence_post('break/start', $this->headers, ['expected_minutes' => 10])->assertOk();
    expect(Cache::get($key))->toBe(['status' => 'ON_BREAK', 'version' => 2, 'session_id' => $sessionId]);
});

it('refuses illegal moves with 409 INVALID_STATE_TRANSITION and the current status', function () {
    // Not checked in: nothing but check-in works.
    foreach (['break/end', 'unavailable', 'available'] as $path) {
        presence_post($path, $this->headers)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION')->assertJsonPath('reason_code', 'not_checked_in')->assertJsonPath('errors.status.0', 'NOT_CHECKED_IN');
    }
    presence_post('break/start', $this->headers, ['expected_minutes' => 10])->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION');

    checkin($this->headers, $this->loc)->assertCreated();
    presence_post('break/start', $this->headers, ['expected_minutes' => 10])->assertOk();
    presence_post('unavailable', $this->headers)->assertStatus(409)->assertJsonPath('reason_code', 'invalid_transition')->assertJsonPath('errors.status.0', 'ON_BREAK');
    presence_post('available', $this->headers)->assertStatus(409)->assertJsonPath('errors.status.0', 'ON_BREAK');

    presence_post('break/end', $this->headers)->assertOk();
    presence_post('unavailable', $this->headers)->assertOk();
    presence_post('break/start', $this->headers, ['expected_minutes' => 10])->assertStatus(409)->assertJsonPath('errors.status.0', 'TEMPORARILY_UNAVAILABLE');
    presence_post('break/end', $this->headers)->assertStatus(409)->assertJsonPath('errors.status.0', 'TEMPORARILY_UNAVAILABLE');

    // Refused moves write nothing: check-in, break start, break end, unavailable.
    expect(events_of($this->doc->id))->toHaveCount(4);
});

it('answers a repeat of the same target state with 200 and the current state, writing nothing', function () {
    Queue::fake();
    $first = checkin($this->headers, $this->loc)->assertCreated()->json('data');
    expect(checkin($this->headers, $this->loc)->assertOk()->json('data'))->toBe($first);

    $break = presence_post('break/start', $this->headers, ['expected_minutes' => 10])->assertOk()->json('data');
    $this->clock->at = $this->clock->at->addMinutes(3);
    expect(presence_post('break/start', $this->headers, ['expected_minutes' => 30])->assertOk()->json('data'))->toBe($break); // the first break end stands
    presence_post('break/end', $this->headers)->assertOk();
    presence_post('break/end', $this->headers)->assertOk()->assertJsonPath('data.status', 'IN');
    presence_post('available', $this->headers)->assertOk()->assertJsonPath('data.status', 'IN');
    presence_post('unavailable', $this->headers)->assertOk();
    presence_post('unavailable', $this->headers)->assertOk()->assertJsonPath('data.status', 'TEMPORARILY_UNAVAILABLE');

    // One event per real change (check-in, break start, break end, unavailable), and no repeat broadcast.
    expect(events_of($this->doc->id))->toHaveCount(4)
        ->and(DB::table('doctor_presence_sessions')->value('version'))->toBe(4);
    Queue::assertPushed(SendBreakReminder::class, 1);
});

it('answers a second check-in while IN with 200, and 409 open_session in any other state', function () {
    checkin($this->headers, $this->loc)->assertCreated();
    $again = checkin($this->headers, $this->loc)->assertOk()->assertJsonPath('data.status', 'IN');
    expect($again->json('data.session_id'))->toBe(DB::table('doctor_presence_sessions')->value('id'))
        ->and(DB::table('doctor_presence_sessions')->whereNull('checked_out_at')->count())->toBe(1)
        ->and(events_of($this->doc->id))->toHaveCount(1);

    presence_post('break/start', $this->headers, ['expected_minutes' => 10])->assertOk();
    checkin($this->headers, $this->loc)->assertStatus(409)->assertJsonPath('code', 'INVALID_STATE_TRANSITION')->assertJsonPath('reason_code', 'open_session')->assertJsonPath('errors.status.0', 'ON_BREAK');
    expect(events_of($this->doc->id))->toHaveCount(2);
});

it('does not offer online presence: a check-in needs a clinic location (D-28 is not approved)', function () {
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-in', ['online' => true], $this->headers)->assertUnprocessable()->assertJsonValidationErrors('clinic_location_id');
    expect(DB::table('doctor_presence_sessions')->count())->toBe(0);
});

it('ignores a location_evidence sent by a client', function () {
    checkin($this->headers, $this->loc, ['location_evidence' => ['lat' => 14.5, 'lng' => 121.0], 'session_kind' => 'ONLINE', 'online' => true, 'current_status' => 'OUT'])->assertCreated();

    $session = DB::table('doctor_presence_sessions')->first();
    expect($session->location_evidence)->toBeNull()->and($session->session_kind)->toBe('IN_PERSON')->and($session->current_status)->toBe('IN');
});

it('validates break length and the scheduled break', function () {
    checkin($this->headers, $this->loc)->assertCreated();

    foreach ([0, -5, 241, 'abc', null] as $bad) {
        presence_post('break/start', $this->headers, ['expected_minutes' => $bad])->assertUnprocessable()->assertJsonValidationErrors('expected_minutes');
    }
    // A scheduled break that is not this doctor's.
    presence_post('break/start', $this->headers, ['expected_minutes' => 15, 'scheduled_break_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('scheduled_break_id');
    presence_post('unavailable', $this->headers, ['reason' => 'Free text about a patient'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    expect(events_of($this->doc->id))->toHaveCount(1);
});

it('records a scheduled break as SCHEDULED and the overrun when it ends late', function () {
    $schedule = (string) Str::uuid7();
    DB::table('doctor_schedules')->insert(['id' => $schedule, 'doctor_profile_id' => $this->doc->id, 'name' => 'Mondays', 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'created_by' => $this->doc->user_id]);
    $shift = (string) Str::uuid7();
    DB::table('doctor_schedule_shifts')->insert(['id' => $shift, 'doctor_schedule_id' => $schedule, 'day_of_week' => 1, 'clinic_location_id' => $this->loc, 'doctor_service_mode_id' => $this->w['mode'], 'starts_at_local' => '09:00', 'ends_at_local' => '12:00', 'timezone' => 'Asia/Manila', 'slot_duration_minutes' => 20, 'buffer_minutes' => 0]);
    $scheduled = (string) Str::uuid7();
    DB::table('doctor_schedule_breaks')->insert(['id' => $scheduled, 'doctor_schedule_shift_id' => $shift, 'starts_at_local' => '10:00', 'ends_at_local' => '10:30', 'label' => 'Snack']);

    checkin($this->headers, $this->loc)->assertCreated();
    presence_post('break/start', $this->headers, ['expected_minutes' => 30, 'scheduled_break_id' => $scheduled])->assertOk();
    $this->clock->at = $this->clock->at->addMinutes(41);
    presence_post('break/end', $this->headers)->assertOk();

    [, $start, $end] = events_of($this->doc->id)->all();
    expect(json_decode($start->metadata, true))->toMatchArray(['break_kind' => 'SCHEDULED', 'scheduled_break_id' => $scheduled])
        ->and(json_decode($end->metadata, true))->toBe(['overrun_minutes' => 11]);
});

// --- check-in rules ---------------------------------------------------------------------------

it('refuses a check-in without an active assignment (422) and counts DOCTOR_CHECKIN_FAILURES', function () {
    Log::spy();
    [, $stranger] = verified_doctor('7654321', 'ACTIVE');

    checkin($stranger, $this->loc)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED')->assertJsonPath('reason_code', 'no_assignment')->assertJsonValidationErrors('clinic_location_id');

    expect(DB::table('doctor_presence_sessions')->count())->toBe(0);
    Log::shouldHaveReceived('info')->with('Doctor check-in refused', Mockery::on(fn ($c) => $c['metric'] === 'DOCTOR_CHECKIN_FAILURES' && $c['reason'] === 'no_assignment'))->once();
});

it('refuses a check-in when the assignment ended', function () {
    DB::table('doctor_clinic_assignments')->where('doctor_profile_id', $this->doc->id)->update(['status' => 'ENDED', 'effective_until' => now()->subDay(), 'end_reason' => 'DOCTOR_LEFT']);

    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonValidationErrors('clinic_location_id');
});

it('refuses a check-in without an active service mode at the location', function () {
    DB::table('doctor_service_modes')->where('doctor_profile_id', $this->doc->id)->update(['status' => 'INACTIVE']);

    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'no_service_mode')->assertJsonValidationErrors('clinic_location_id');
});

it('refuses a doctor who is not active, and an unknown or inactive location', function () {
    DB::table('doctor_profiles')->where('id', $this->doc->id)->update(['onboarding_status' => 'VERIFIED']);
    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'doctor_not_active')->assertJsonValidationErrors('doctor_id');

    DB::table('doctor_profiles')->where('id', $this->doc->id)->update(['onboarding_status' => 'ACTIVE']);
    checkin($this->headers, (string) Str::uuid())->assertUnprocessable()->assertJsonPath('reason_code', 'location_not_active')->assertJsonValidationErrors('clinic_location_id');
    DB::table('clinic_locations')->where('id', $this->loc)->update(['status' => 'INACTIVE']);
    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'location_not_active')->assertJsonValidationErrors('clinic_location_id');
    checkin($this->headers, 'not-a-uuid')->assertUnprocessable();
});

it('refuses a check-in outside opening hours, and on a closed holiday', function () {
    $this->clock->at = next_monday_at('06:30'); // clinic opens at 08:00
    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'outside_hours')->assertJsonValidationErrors('clinic_location_id');

    $this->clock->at = next_monday_at('17:00'); // closing time itself is closed
    checkin($this->headers, $this->loc)->assertUnprocessable();

    $this->clock->at = next_monday_at('10:00');
    DB::table('clinic_holidays')->insert(['id' => (string) Str::uuid7(), 'clinic_id' => $this->w['clinic'], 'date' => $this->clock->at->setTimezone('Asia/Manila')->toDateString(), 'name' => 'Founders Day', 'is_closed' => true]);
    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'closed_holiday')->assertJsonValidationErrors('clinic_location_id');
    expect(DB::table('doctor_presence_sessions')->count())->toBe(0);
});

it('uses special holiday hours instead of the weekly hours', function () {
    $date = $this->clock->at->setTimezone('Asia/Manila')->toDateString();
    DB::table('clinic_holidays')->insert(['id' => (string) Str::uuid7(), 'clinic_id' => $this->w['clinic'], 'date' => $date, 'name' => 'Half day', 'is_closed' => false, 'special_opens_at' => '13:00', 'special_closes_at' => '15:00']);

    checkin($this->headers, $this->loc)->assertUnprocessable(); // 10:00 is outside the special hours
    $this->clock->at = next_monday_at('13:30');
    checkin($this->headers, $this->loc)->assertCreated();
});

it('allows a check-in inside the early window before a shift while the clinic is still closed', function () {
    DB::table('clinic_operating_hours')->where('clinic_location_id', $this->loc)->delete();
    foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
        DB::table('clinic_operating_hours')->insert(['id' => (string) Str::uuid7(), 'clinic_location_id' => $this->loc, 'day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00', 'created_at' => now(), 'updated_at' => now()]);
    }
    $schedule = (string) Str::uuid7();
    DB::table('doctor_schedules')->insert(['id' => $schedule, 'doctor_profile_id' => $this->doc->id, 'name' => 'Mondays', 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'created_by' => $this->doc->user_id]);
    DB::table('doctor_schedule_shifts')->insert(['id' => (string) Str::uuid7(), 'doctor_schedule_id' => $schedule, 'day_of_week' => 1, 'clinic_location_id' => $this->loc, 'doctor_service_mode_id' => $this->w['mode'], 'starts_at_local' => '09:00', 'ends_at_local' => '12:00', 'timezone' => 'Asia/Manila', 'slot_duration_minutes' => 20, 'buffer_minutes' => 0]);

    $this->clock->at = next_monday_at('07:30'); // 90 minutes before the shift: too early
    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonValidationErrors('clinic_location_id');

    $this->clock->at = next_monday_at('08:15'); // 45 minutes before: inside the 60-minute window
    checkin($this->headers, $this->loc)->assertCreated()->assertJsonPath('data.status', 'IN');
});

it('does not open the early window for a shift on another weekday or another location', function () {
    DB::table('clinic_operating_hours')->where('clinic_location_id', $this->loc)->delete();
    $schedule = (string) Str::uuid7();
    DB::table('doctor_schedules')->insert(['id' => $schedule, 'doctor_profile_id' => $this->doc->id, 'name' => 'Tuesdays', 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'created_by' => $this->doc->user_id]);
    DB::table('doctor_schedule_shifts')->insert(['id' => (string) Str::uuid7(), 'doctor_schedule_id' => $schedule, 'day_of_week' => 2, 'clinic_location_id' => $this->loc, 'doctor_service_mode_id' => $this->w['mode'], 'starts_at_local' => '09:00', 'ends_at_local' => '12:00', 'timezone' => 'Asia/Manila', 'slot_duration_minutes' => 20, 'buffer_minutes' => 0]);

    $this->clock->at = next_monday_at('08:30'); // Monday: the shift is on Tuesday
    checkin($this->headers, $this->loc)->assertUnprocessable();
});

it('a doctor can check in again after the previous session closed (a new session)', function () {
    checkin($this->headers, $this->loc)->assertCreated();
    DB::table('doctor_presence_sessions')->update(['current_status' => 'OUT', 'checked_out_at' => $this->clock->at->addHour(), 'checkout_reason' => 'END_OF_SHIFT', 'checked_out_by' => $this->doc->user_id, 'checked_out_method' => 'DOCTOR_APP']);

    checkin($this->headers, $this->loc)->assertCreated();
    expect(DB::table('doctor_presence_sessions')->count())->toBe(2)->and(DB::table('doctor_presence_sessions')->whereNull('checked_out_at')->count())->toBe(1);
});

// --- on behalf and authorization ---------------------------------------------------------------

it('lets the clinic manager check a doctor in and notifies the doctor generically', function () {
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-in', ['doctor_id' => $this->doc->id, 'clinic_location_id' => $this->loc, 'reason' => 'COVERING_COLLEAGUE'], $this->w['manager'])
        ->assertCreated()->assertJsonPath('data.status', 'IN');

    $event = events_of($this->doc->id)->first();
    expect($event->change_method)->toBe('STAFF_CONSOLE')->and($event->changed_by)->not->toBe($this->doc->user_id)
        ->and(DB::table('doctor_presence_sessions')->value('checked_in_by'))->toBe($event->changed_by);

    $note = DB::table('notifications')->where('notifiable_id', $this->doc->user_id)->where('type', 'presence.changed_by_staff')->first();
    $notices = fn () => DB::table('notifications')->where('notifiable_id', $this->doc->user_id)->where('type', 'presence.changed_by_staff')->count();
    $data = json_decode($note->data, true);
    expect($note)->not->toBeNull()->and($data['title'])->toBe('Your clinic status was updated')
        ->and(json_encode($data))->not->toContain('COVERING')
        ->and(DB::table('audit_logs')->where('action', 'presence.checked_in')->value('actor_user_id'))->toBe($event->changed_by);

    // The same manager can then run the doctor's breaks.
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/break/start', ['doctor_id' => $this->doc->id, 'clinic_location_id' => $this->loc, 'expected_minutes' => 10], $this->w['manager'])->assertOk()->assertJsonPath('data.status', 'ON_BREAK');
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/break/end', ['doctor_id' => $this->doc->id, 'clinic_location_id' => $this->loc], $this->w['manager'])->assertOk()->assertJsonPath('data.status', 'IN');
    expect(events_of($this->doc->id)->pluck('change_method')->unique()->all())->toBe(['STAFF_CONSOLE'])
        ->and($notices())->toBe(1); // three staff changes, one notice in five minutes (L5)

    $this->travel(6)->minutes();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/unavailable', ['doctor_id' => $this->doc->id, 'clinic_location_id' => $this->loc], $this->w['manager'])->assertOk();
    expect($notices())->toBe(2);
});

it('records ADMIN as the method when a platform administrator acts for the doctor', function () {
    $admin = session_for(account(Role::Admin));
    $this->postJson('/api/v1/presence/check-in', ['doctor_id' => $this->doc->id, 'clinic_location_id' => $this->loc], $admin)->assertCreated();

    expect(events_of($this->doc->id)->first()->change_method)->toBe('ADMIN');
});

it('refuses staff of an unrelated clinic, and a doctor acting for another doctor', function () {
    [, , $otherManager] = clinic_with_manager('Diliman Medical Clinic');
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-in', ['doctor_id' => $this->doc->id, 'clinic_location_id' => $this->loc], $otherManager)->assertForbidden();

    [$other, $otherHeaders] = verified_doctor('7654321', 'ACTIVE');
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-in', ['doctor_id' => $this->doc->id, 'clinic_location_id' => $this->loc], $otherHeaders)->assertForbidden();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-in', ['clinic_location_id' => $this->loc], session_for(patient_account()))->assertForbidden();

    expect(DB::table('doctor_presence_sessions')->count())->toBe(0);
});

it('refuses staff restricted to another location, and allows staff restricted to this one', function () {
    $locB = add_location($this->w['clinic'], $this->w['manager'], 'Branch B');
    [, $staffB] = clinic_member($this->w['clinic'], Role::ClinicStaff, $locB);
    [, $staffA] = clinic_member($this->w['clinic'], Role::ClinicStaff, $this->loc);

    $body = ['doctor_id' => $this->doc->id, 'clinic_location_id' => $this->loc];
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-in', $body, $staffB)->assertForbidden();
    expect(DB::table('doctor_presence_sessions')->count())->toBe(0);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-in', $body, $staffA)->assertCreated();

    // Break control by staff restricted to the other location is refused too, and reveals nothing about the session.
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/break/start', [...$body, 'expected_minutes' => 5], $staffB)->assertForbidden();
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/unavailable', $body, $staffB)->assertForbidden();
});

it('refuses staff without presence.manage and a removed staff member', function () {
    [, $support] = [null, session_for(account(Role::SupportAgent))];
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-in', ['doctor_id' => $this->doc->id, 'clinic_location_id' => $this->loc], $support)->assertForbidden();

    [, $removed] = clinic_member($this->w['clinic'], Role::ClinicStaff, null, 'REMOVED');
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-in', ['doctor_id' => $this->doc->id, 'clinic_location_id' => $this->loc], $removed)->assertForbidden();
});

it('needs the location when acting for a doctor, and a session at that location', function () {
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/break/end', ['doctor_id' => $this->doc->id], $this->w['manager'])->assertUnprocessable()->assertJsonValidationErrors('clinic_location_id');

    checkin($this->headers, $this->loc)->assertCreated();
    app('auth')->forgetGuards();
    $locB = add_location($this->w['clinic'], $this->w['manager'], 'Branch B');
    // The manager covers both locations, but the doctor's session is at the first: a wrong location is a 409, not a hint.
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/break/start', ['doctor_id' => $this->doc->id, 'clinic_location_id' => $locB, 'expected_minutes' => 5], $this->w['manager'])->assertStatus(409)->assertJsonPath('errors.status.0', 'NOT_CHECKED_IN');
});

it('requires authentication on every presence write', function () {
    foreach (['check-in', 'break/start', 'break/end', 'unavailable', 'available'] as $path) {
        app('auth')->forgetGuards();
        $this->postJson("/api/v1/presence/{$path}", ['clinic_location_id' => $this->loc, 'expected_minutes' => 5])->assertUnauthorized();
    }
});

// --- public status -----------------------------------------------------------------------------

it('shows a public, whitelisted status and hides reasons, actor, device and times', function () {
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/presence/doctors/{$this->doc->id}")->assertOk()->assertJsonPath('data', ['doctor_id' => $this->doc->id, 'status' => 'NOT_IN', 'clinic_location_id' => null]);

    checkin($this->headers, $this->loc, ['reason' => 'LATE_ARRIVAL'])->assertCreated();
    app('auth')->forgetGuards();
    $in = $this->getJson("/api/v1/presence/doctors/{$this->doc->id}")->assertOk();
    expect($in->json('data'))->toBe(['doctor_id' => $this->doc->id, 'status' => 'IN', 'clinic_location_id' => $this->loc]);

    presence_post('unavailable', $this->headers, ['reason' => 'EMERGENCY'])->assertOk();
    app('auth')->forgetGuards();
    $away = $this->getJson("/api/v1/presence/doctors/{$this->doc->id}")->assertOk();
    expect($away->json('data.status'))->toBe('AWAY')->and(array_keys($away->json('data')))->toBe(['doctor_id', 'status', 'clinic_location_id'])
        ->and($away->getContent())->not->toContain('EMERGENCY')->not->toContain('LATE_ARRIVAL')->not->toContain($this->doc->user_id)->not->toContain('checked_in')->not->toContain('device')->not->toContain('TEMPORARILY');

    presence_post('available', $this->headers)->assertOk();
    presence_post('break/start', $this->headers, ['expected_minutes' => 10])->assertOk();
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/presence/doctors/{$this->doc->id}")->assertJsonPath('data.status', 'ON_BREAK');
});

it('hides doctors who are not active and unknown ids from the public status', function () {
    DB::table('doctor_profiles')->where('id', $this->doc->id)->update(['onboarding_status' => 'UNDER_REVIEW']);
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/presence/doctors/{$this->doc->id}")->assertNotFound();
    $this->getJson('/api/v1/presence/doctors/'.Str::uuid())->assertNotFound();
    $this->getJson('/api/v1/presence/doctors/not-a-uuid')->assertNotFound();
});

// --- real-time ---------------------------------------------------------------------------------

it('queues whitelisted broadcasts on private-clinic.{location} after commit', function () {
    Queue::fake();

    checkin($this->headers, $this->loc, ['reason' => 'EMERGENCY'])->assertCreated();
    presence_post('break/start', $this->headers, ['expected_minutes' => 10])->assertOk();
    presence_post('break/end', $this->headers)->assertOk();
    presence_post('unavailable', $this->headers, ['reason' => 'EMERGENCY'])->assertOk();

    $sent = fn (string $class) => Queue::pushed(BroadcastEvent::class, fn ($job) => $class === $job->event::class)->map->event;
    expect($sent(DoctorCheckedIn::class))->toHaveCount(1)
        ->and($sent(DoctorBreakStarted::class))->toHaveCount(1)
        ->and($sent(DoctorBreakEnded::class))->toHaveCount(1)
        ->and($sent(DoctorPresenceUpdated::class))->toHaveCount(4);

    $all = Queue::pushed(BroadcastEvent::class)->map->event->filter(fn ($e) => $e instanceof RealtimeEvent);
    foreach ($all->filter(fn ($e) => str_starts_with($e::class, 'App\\Modules\\Presence')) as $event) {
        expect(collect($event->broadcastOn())->map->name->all())->toBe(["private-clinic.{$this->loc}"])
            ->and($event->broadcastQueue())->toBe('notifications')
            ->and(json_encode($event->broadcastWith()))->not->toContain('EMERGENCY');
    }
    Queue::assertPushedOn('notifications', BroadcastEvent::class);
});

it('does not broadcast when the change is refused', function () {
    Queue::fake();

    presence_post('break/end', $this->headers)->assertStatus(409);
    checkin($this->headers, (string) Str::uuid())->assertUnprocessable();

    Queue::assertNothingPushed();
});

it('keeps each presence broadcast to its whitelist of ids, status and version', function () {
    $ids = ['doctor' => '018f1c3e-7b1a-7000-8000-000000000001', 'location' => '018f1c3e-7b1a-7000-8000-000000000002', 'session' => '018f1c3e-7b1a-7000-8000-000000000003'];
    $end = now()->addMinutes(10)->toImmutable();

    $events = [
        new DoctorCheckedIn($ids['doctor'], $ids['location'], $ids['session'], PresenceStatus::In, 1),
        new DoctorPresenceUpdated($ids['doctor'], $ids['location'], $ids['session'], PresenceStatus::OnBreak, 2),
        new DoctorBreakStarted($ids['doctor'], $ids['location'], $ids['session'], $end, 2),
        new DoctorBreakEnded($ids['doctor'], $ids['location'], $ids['session'], 3),
    ];

    foreach ($events as $event) {
        expect(array_keys($event->broadcastWith()))->toEqualCanonicalizing($event::ALLOWED_KEYS)
            ->and($event->broadcastOn())->toHaveCount(1)
            ->and($event->broadcastOn()[0]->name)->toBe("private-clinic.{$ids['location']}");
    }
    expect($events[1]->broadcastWith()['status'])->toBe('ON_BREAK')
        ->and($events[2]->broadcastWith()['break_expected_end_at'])->toBe($end->toIso8601ZuluString());
});

it('counts a check-in refused because a session is already open as DOCTOR_CHECKIN_FAILURES', function () {
    checkin($this->headers, $this->loc)->assertCreated();
    presence_post('break/start', $this->headers, ['expected_minutes' => 10])->assertOk();
    Log::spy();

    checkin($this->headers, $this->loc)->assertStatus(409);

    Log::shouldHaveReceived('info')->with('Doctor check-in refused', Mockery::on(fn ($c) => $c['metric'] === 'DOCTOR_CHECKIN_FAILURES' && $c['reason'] === 'open_session'))->once();

});

it('does not count a harmless repeat check-in as a failure', function () {
    checkin($this->headers, $this->loc)->assertCreated();
    Log::spy();

    checkin($this->headers, $this->loc)->assertOk();

    Log::shouldNotHaveReceived('info', fn ($message) => $message === 'Doctor check-in refused');
});
