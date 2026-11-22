<?php

use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Presence\Services\PresenceCache;
use App\Modules\Rbac\Enums\Role;
use App\Modules\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/*
 * Increment 5.3 review fixes: shift windows and midnight, machine-readable reasons, idempotent repeats,
 * location privacy for "checked in elsewhere", on-behalf probing, devices, rate limits, and the public
 * status mitigations.
 */

beforeEach(function () {
    seed_roles();
    fake_messages();
    $this->clock = pin_clock();
    $this->w = presence_world();
    $this->loc = $this->w['location'];
    $this->doc = $this->w['doctor'];
    $this->headers = $this->w['doctorHeaders'];

    $this->hours = function (array $rows): void {
        DB::table('clinic_operating_hours')->where('clinic_location_id', $this->loc)->delete();
        foreach ($rows as [$day, $opens, $closes]) {
            DB::table('clinic_operating_hours')->insert(['id' => (string) Str::uuid7(), 'clinic_location_id' => $this->loc, 'day_of_week' => $day, 'opens_at' => $opens, 'closes_at' => $closes, 'created_at' => now(), 'updated_at' => now()]);
        }
    };
    $this->shift = function (int $day, string $starts, string $ends): void {
        $schedule = (string) Str::uuid7();
        DB::table('doctor_schedules')->insert(['id' => $schedule, 'doctor_profile_id' => $this->doc->id, 'name' => 'Shift', 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'created_by' => $this->doc->user_id]);
        DB::table('doctor_schedule_shifts')->insert(['id' => (string) Str::uuid7(), 'doctor_schedule_id' => $schedule, 'day_of_week' => $day, 'clinic_location_id' => $this->loc, 'doctor_service_mode_id' => $this->w['mode'], 'starts_at_local' => $starts, 'ends_at_local' => $ends, 'timezone' => 'Asia/Manila', 'slot_duration_minutes' => 20, 'buffer_minutes' => 0]);
    };
    $this->at = fn (string $time, int $plusDays = 0) => next_monday_at($time)->addDays($plusDays);
    $this->secondLocation = function (): string {
        app('auth')->forgetGuards();
        $locB = add_location($this->w['clinic'], $this->w['manager'], 'Branch B');
        $this->putJson("/api/v1/clinics/{$this->w['clinic']}/locations/{$locB}/operating-hours", ['hours' => array_map(fn ($d) => ['day_of_week' => $d, 'opens_at' => '08:00', 'closes_at' => '17:00'], [1, 2, 3, 4, 5, 6, 7])], $this->w['manager'])->assertOk();
        app('auth')->forgetGuards();
        assign($this->w['clinic'], $locB, $this->w['manager'], $this->headers);
        $service = $this->postJson('/api/v1/doctors/me/services', in_person($locB, 65000), $this->headers)->assertCreated()->json('data.id');
        $this->putJson("/api/v1/doctors/me/services/{$service}/mode", ['service_mode' => 'WALK_IN'], $this->headers)->assertOk();
        app('auth')->forgetGuards();

        return $locB;
    };
});

// --- F1: shift windows ------------------------------------------------------------------------

it('lets an evening shift check in when no clinic hours were entered, from 60 minutes before until it ends', function () {
    ($this->hours)([]);
    ($this->shift)(1, '18:00', '21:00');

    $this->clock->at = ($this->at)('16:30'); // 90 minutes before
    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'outside_hours');
    $this->clock->at = ($this->at)('21:00'); // the shift has ended
    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'outside_hours');

    $this->clock->at = ($this->at)('20:59');
    checkin($this->headers, $this->loc)->assertCreated();
});

it('lets a late doctor in while the clinic is open, even after the shift started', function () {
    ($this->shift)(1, '09:00', '12:00');

    $this->clock->at = ($this->at)('15:00'); // the shift is over, the clinic is open until 17:00
    checkin($this->headers, $this->loc)->assertCreated();
});

it('opens the window for a shift that started while the clinic is closed', function () {
    ($this->hours)([[1, '10:00', '17:00']]);
    ($this->shift)(1, '08:00', '12:00');

    $this->clock->at = ($this->at)('09:00'); // clinic still closed, doctor late for the shift
    checkin($this->headers, $this->loc)->assertCreated();
});

// --- F3: midnight, holidays ---------------------------------------------------------------------

it('handles clinic hours split at midnight (22:00-24:00, then 00:00-06:00)', function () {
    ($this->hours)([[1, '22:00', '24:00'], [2, '00:00', '06:00']]);

    $this->clock->at = ($this->at)('23:30');
    checkin($this->headers, $this->loc)->assertCreated();
    DB::table('doctor_presence_sessions')->update(['current_status' => 'OUT', 'checked_out_at' => $this->clock->at->addHour(), 'checkout_reason' => 'END_OF_SHIFT', 'checked_out_by' => $this->doc->user_id, 'checked_out_method' => 'DOCTOR_APP']);

    $this->clock->at = ($this->at)('00:30', 1); // Tuesday just after midnight
    checkin($this->headers, $this->loc)->assertCreated();
});

it('sees a shift just after midnight from before midnight, with no clamp at 23:59', function () {
    ($this->hours)([]);
    ($this->shift)(2, '00:30', '04:00'); // Tuesday

    $this->clock->at = ($this->at)('23:15'); // Monday, 75 minutes before
    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'outside_hours');

    $this->clock->at = ($this->at)('23:45'); // 45 minutes before, across midnight
    checkin($this->headers, $this->loc)->assertCreated();
});

it('ignores a closed holiday on a neighbouring day', function () {
    DB::table('clinic_holidays')->insert(['id' => (string) Str::uuid7(), 'clinic_id' => $this->w['clinic'], 'date' => $this->clock->at->setTimezone('Asia/Manila')->addDay()->toDateString(), 'name' => 'Tomorrow', 'is_closed' => true]);

    checkin($this->headers, $this->loc)->assertCreated();
});

// --- F12 and L1: a session elsewhere --------------------------------------------------------------

it('tells the doctor and staff of the open location where the session is, and others only "elsewhere"', function () {
    $locB = ($this->secondLocation)();
    [, $staffA] = clinic_member($this->w['clinic'], Role::ClinicStaff, $this->loc);
    [, $staffB] = clinic_member($this->w['clinic'], Role::ClinicStaff, $locB);
    checkin($this->headers, $this->loc)->assertCreated();

    // The doctor: where the open session is.
    checkin($this->headers, $locB)->assertStatus(409)->assertJsonPath('reason_code', 'open_session')->assertJsonPath('errors.clinic_location_id.0', $this->loc);

    // The clinic-wide manager is entitled to location A.
    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/check-in', ['doctor_id' => $this->doc->id, 'clinic_location_id' => $locB], $this->w['manager'])->assertStatus(409)->assertJsonPath('errors.clinic_location_id.0', $this->loc);

    // Staff restricted to B act at B only: they learn neither the location nor the status of the session at A.
    app('auth')->forgetGuards();
    $other = $this->postJson('/api/v1/presence/check-in', ['doctor_id' => $this->doc->id, 'clinic_location_id' => $locB], $staffB)->assertStatus(409)->assertJsonPath('reason_code', 'open_session');
    expect($other->getContent())->not->toContain($this->loc)->not->toContain('"IN"')->and($other->json('errors'))->toBe([]);
    expect(DB::table('doctor_presence_sessions')->whereNull('checked_out_at')->count())->toBe(1);
});

it('answers no_assignment the same whether or not the doctor is checked in elsewhere (staff cannot probe presence)', function () {
    $locC = add_location($this->w['clinic'], $this->w['manager'], 'Branch C'); // the doctor has no assignment here
    app('auth')->forgetGuards();
    [, $staffC] = clinic_member($this->w['clinic'], Role::ClinicStaff, $locC);
    $body = ['doctor_id' => $this->doc->id, 'clinic_location_id' => $locC];

    app('auth')->forgetGuards();
    $before = $this->postJson('/api/v1/presence/check-in', $body, $staffC)->assertUnprocessable()->assertJsonPath('reason_code', 'no_assignment');
    checkin($this->headers, $this->loc)->assertCreated();
    app('auth')->forgetGuards();
    $after = $this->postJson('/api/v1/presence/check-in', $body, $staffC)->assertUnprocessable()->assertJsonPath('reason_code', 'no_assignment');

    $strip = fn ($r) => collect($r->json())->except('request_id')->all();
    expect($strip($after))->toBe($strip($before))
        ->and(DB::table('doctor_presence_events')->count())->toBe(1);
});

// --- L4 clamp, L5 done elsewhere, L7 -------------------------------------------------------------------

it('logs a refused on-behalf action with ids only, at most once a minute per user and location', function () {
    Log::spy();
    [, $other] = clinic_with_manager('Diliman Medical Clinic')[2] ? [null, clinic_with_manager('Pasig Medical Clinic')[2]] : [null, null];

    foreach (range(1, 3) as $_) {
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/presence/check-in', ['doctor_id' => $this->doc->id, 'clinic_location_id' => $this->loc, 'reason' => 'PERSONAL'], $other)->assertForbidden();
    }

    Log::shouldHaveReceived('warning')->with('Presence action on behalf refused', Mockery::on(fn ($c) => $c['metric'] === 'PRESENCE_ONBEHALF_DENIED' && $c['doctor_id'] === $this->doc->id && $c['clinic_location_id'] === $this->loc && array_keys($c) === ['metric', 'user_id', 'doctor_id', 'clinic_location_id']))->once();
});

// --- L8: roles, devices, limits --------------------------------------------------------------------

it('refuses finance, medical admin, moderator and support on behalf', function () {
    foreach ([Role::FinanceOfficer, Role::MedicalAdmin, Role::Moderator, Role::SupportAgent] as $role) {
        $headers = session_for(account($role));
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/presence/check-in', ['doctor_id' => $this->doc->id, 'clinic_location_id' => $this->loc], $headers)->assertForbidden();
    }
    expect(DB::table('doctor_presence_sessions')->count())->toBe(0);
});

it('ignores a source_device_id sent by the client and uses the session device', function () {
    $stranger = patient_account();
    $foreign = (string) Str::uuid7();
    DB::table('user_devices')->insert(['id' => $foreign, 'user_id' => $stranger->id, 'platform' => 'ANDROID', 'created_at' => now(), 'updated_at' => now()]);
    $own = DB::table('personal_access_tokens')->where('tokenable_id', $this->doc->user_id)->latest('id')->value('user_device_id');
    expect($own)->not->toBeNull()->and($foreign)->not->toBe($own);

    checkin($this->headers, $this->loc, ['source_device_id' => $foreign, 'device_id' => $foreign, 'changed_by' => $stranger->id, 'change_method' => 'SYSTEM'])->assertCreated();

    $event = events_of($this->doc->id)->first();
    expect($event->source_device_id)->toBe($own)->not->toBe($foreign)->and($event->changed_by)->toBe($this->doc->user_id)->and($event->change_method)->toBe('DOCTOR_APP');
});

it('records the device of a first-party web session (cookie), not a token device', function () {
    $user = User::findOrFail($this->doc->user_id);
    app('auth')->forgetGuards();
    $this->withHeader('Origin', WEB_ORIGIN)->postJson('/api/v1/auth/login', ['identifier' => $user->email, 'password' => TEST_PASSWORD])->assertOk();
    $webDevice = DB::table('user_devices')->where('user_id', $user->id)->where('platform', 'WEB')->value('id');

    $this->withHeader('Origin', WEB_ORIGIN)->postJson('/api/v1/presence/check-in', ['clinic_location_id' => $this->loc])->assertCreated();

    expect($webDevice)->not->toBeNull()->and(events_of($this->doc->id)->first()->source_device_id)->toBe($webDevice);
});

it('answers 429 after 30 presence writes a minute, and after 60 public reads a minute per IP', function () {
    foreach (range(1, 30) as $_) {
        presence_post('break/end', $this->headers)->assertStatus(409);
    }
    presence_post('break/end', $this->headers)->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');

    app('auth')->forgetGuards();
    foreach (range(1, 60) as $_) {
        $this->getJson("/api/v1/presence/doctors/{$this->doc->id}")->assertOk();
    }
    $this->getJson("/api/v1/presence/doctors/{$this->doc->id}")->assertStatus(429);
});

// --- M1: public status -------------------------------------------------------------------------------

it('serves the public status with a revalidating Cache-Control and drops its server cache on every change', function () {
    app('auth')->forgetGuards();
    $res = $this->getJson("/api/v1/presence/doctors/{$this->doc->id}")->assertOk()->assertJsonPath('data.status', 'NOT_IN');
    expect($res->headers->get('Cache-Control'))->toContain('private')->toContain('no-cache')->not->toContain('public')->not->toContain('max-age=');

    checkin($this->headers, $this->loc)->assertCreated();
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/presence/doctors/{$this->doc->id}")->assertJsonPath('data.status', 'IN'); // the first answer was not served stale
    presence_post('break/start', $this->headers, ['expected_minutes' => 5])->assertOk();
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/presence/doctors/{$this->doc->id}")->assertJsonPath('data.status', 'ON_BREAK');
});

it('rechecks the location and clinic at read time, even inside the server cache', function () {
    checkin($this->headers, $this->loc)->assertCreated();
    app('auth')->forgetGuards();
    $this->getJson("/api/v1/presence/doctors/{$this->doc->id}")->assertJsonPath('data.status', 'IN')->assertJsonPath('data.clinic_location_id', $this->loc);

    DB::table('clinic_locations')->where('id', $this->loc)->update(['status' => 'INACTIVE']);
    $this->getJson("/api/v1/presence/doctors/{$this->doc->id}")->assertJsonPath('data', ['doctor_id' => $this->doc->id, 'status' => 'NOT_IN', 'clinic_location_id' => null]);

    DB::table('clinic_locations')->where('id', $this->loc)->update(['status' => 'ACTIVE']);
    DB::table('clinics')->where('id', $this->w['clinic'])->update(['status' => 'INACTIVE']);
    $this->getJson("/api/v1/presence/doctors/{$this->doc->id}")->assertJsonPath('data.status', 'NOT_IN');
});

// --- Redis hint ------------------------------------------------------------------------------------------

it('keeps the Redis hint for 24 hours and ignores an older version of the same session', function () {
    $cache = app(PresenceCache::class);
    $key = PresenceCache::key($this->doc->id, $this->loc);

    $cache->put($this->doc->id, $this->loc, 'session-1', PresenceStatus::OnBreak, 3);
    $cache->put($this->doc->id, $this->loc, 'session-1', PresenceStatus::In, 2); // an older change landing late
    expect(Cache::get($key))->toBe(['status' => 'ON_BREAK', 'version' => 3, 'session_id' => 'session-1']);

    $cache->put($this->doc->id, $this->loc, 'session-2', PresenceStatus::In, 1); // a new session starts again at version 1
    expect(Cache::get($key)['session_id'])->toBe('session-2');

    $this->travel(23)->hours();
    expect(Cache::has($key))->toBeTrue();
    $this->travel(2)->hours();
    expect(Cache::has($key))->toBeFalse();
});

it('rejects reasons that are not one of the fixed codes', function () {
    foreach (['Phone call with Dr. Reyes about patient Cruz', 'phone_call', 123] as $bad) {
        checkin($this->headers, $this->loc, ['reason' => $bad])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }
    checkin($this->headers, $this->loc, ['reason' => null])->assertCreated();
    expect(events_of($this->doc->id)->first()->reason)->toBeNull();
});

// --- review round 3 ---------------------------------------------------------------------------------

it('refuses a check-in while a clinic closure covers now, but not for a doctor leave', function () {
    $exception = fn (string $type, string $effect, ?string $location, string $from, string $until, string $status = 'ACTIVE') => DB::table('doctor_schedule_exceptions')->insert([
        'id' => (string) Str::uuid7(), 'doctor_profile_id' => $this->doc->id, 'clinic_location_id' => $location, 'exception_type' => $type, 'effect' => $effect, 'priority' => 1,
        'starts_at' => $from, 'ends_at' => $until, 'requested_by' => $this->doc->user_id, 'status' => $status, 'cancelled_at' => $status === 'CANCELLED' ? now() : null,
    ]);
    $now = $this->clock->at;

    // Leave and sickness are the doctor's own business: presence is not blocked.
    $exception('VACATION', 'BLOCK', null, $now->subHour()->toDateTimeString(), $now->addHour()->toDateTimeString());
    $exception('SICK_LEAVE', 'BLOCK', $this->loc, $now->subHour()->toDateTimeString(), $now->addHour()->toDateTimeString());
    // A closure that has not started, one that ended, and a cancelled one do not block either.
    $exception('CLINIC_CLOSURE', 'BLOCK', $this->loc, $now->addHour()->toDateTimeString(), $now->addHours(3)->toDateTimeString());
    $exception('CLINIC_CLOSURE', 'BLOCK', $this->loc, $now->subHours(3)->toDateTimeString(), $now->subHour()->toDateTimeString());
    $exception('CLINIC_CLOSURE', 'BLOCK', $this->loc, $now->subHour()->toDateTimeString(), $now->addHour()->toDateTimeString(), 'CANCELLED');
    checkin($this->headers, $this->loc)->assertCreated();
    DB::table('doctor_presence_sessions')->update(['current_status' => 'OUT', 'checked_out_at' => $now->addMinute(), 'checkout_reason' => 'OTHER', 'checked_out_by' => $this->doc->user_id, 'checked_out_method' => 'DOCTOR_APP']);

    // A closure at another location does not apply here; one covering now at this location, or at every place, does.
    $otherLocation = (string) Str::uuid7();
    DB::table('clinic_locations')->insert(['id' => $otherLocation, 'clinic_id' => $this->w['clinic'], 'name' => 'Elsewhere', 'address_line1' => '1 Road', 'city' => 'Makati', 'created_at' => now(), 'updated_at' => now()]);
    $exception('CLINIC_CLOSURE', 'BLOCK', $otherLocation, $now->subHour()->toDateTimeString(), $now->addHour()->toDateTimeString());
    $exception('CLINIC_CLOSURE', 'BLOCK', $this->loc, $now->subMinutes(5)->toDateTimeString(), $now->addMinutes(5)->toDateTimeString());
    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'clinic_closed')->assertJsonValidationErrors('clinic_location_id');
});

it('lets a location holiday override a clinic-wide one', function () {
    $date = $this->clock->at->setTimezone('Asia/Manila')->toDateString();
    $holiday = fn (?string $location, bool $closed, ?string $opens = null, ?string $closes = null) => DB::table('clinic_holidays')->insert(['id' => (string) Str::uuid7(), 'clinic_id' => $this->w['clinic'], 'clinic_location_id' => $location, 'date' => $date, 'name' => 'Holiday', 'is_closed' => $closed, 'special_opens_at' => $opens, 'special_closes_at' => $closes]);

    // Clinic-wide closed, but this location opens 09:00-12:00 that day: 10:00 is open.
    $holiday(null, true);
    $holiday($this->loc, false, '09:00', '12:00');
    checkin($this->headers, $this->loc)->assertCreated();
    DB::table('doctor_presence_sessions')->update(['current_status' => 'OUT', 'checked_out_at' => $this->clock->at->addMinute(), 'checkout_reason' => 'OTHER', 'checked_out_by' => $this->doc->user_id, 'checked_out_method' => 'DOCTOR_APP']);

    // The reverse: clinic-wide special hours, this location closed.
    DB::table('clinic_holidays')->delete();
    $holiday(null, false, '08:00', '17:00');
    $holiday($this->loc, true);
    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'closed_holiday');
});

it('clips shift windows to special holiday hours', function () {
    ($this->hours)([]);
    ($this->shift)(1, '08:00', '12:00');
    DB::table('clinic_holidays')->insert(['id' => (string) Str::uuid7(), 'clinic_id' => $this->w['clinic'], 'date' => $this->clock->at->setTimezone('Asia/Manila')->toDateString(), 'name' => 'Half day', 'is_closed' => false, 'special_opens_at' => '09:00', 'special_closes_at' => '11:00']);

    $this->clock->at = ($this->at)('08:30'); // inside the shift's early window and the shift, but before the special hours
    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'outside_hours');
    $this->clock->at = ($this->at)('11:30'); // after the special hours close
    checkin($this->headers, $this->loc)->assertUnprocessable();
    $this->clock->at = ($this->at)('10:00');
    checkin($this->headers, $this->loc)->assertCreated();
});

it('ignores archived schedules, expired schedules and opening hours outside their dates', function () {
    ($this->hours)([]);
    $schedule = fn (string $status, string $from, ?string $until) => tap((string) Str::uuid7(), function ($id) use ($status, $from, $until) {
        DB::table('doctor_schedules')->insert(['id' => $id, 'doctor_profile_id' => $this->doc->id, 'name' => 'S', 'effective_from' => $from, 'effective_until' => $until, 'status' => $status, 'created_by' => $this->doc->user_id]);
        DB::table('doctor_schedule_shifts')->insert(['id' => (string) Str::uuid7(), 'doctor_schedule_id' => $id, 'day_of_week' => 1, 'clinic_location_id' => $this->loc, 'doctor_service_mode_id' => $this->w['mode'], 'starts_at_local' => '09:00', 'ends_at_local' => '12:00', 'timezone' => 'Asia/Manila', 'slot_duration_minutes' => 20, 'buffer_minutes' => 0]);
    });
    $schedule('ARCHIVED', '2026-01-01', null);
    $schedule('DRAFT', '2026-01-01', null);
    $schedule('ACTIVE', '2026-01-01', '2026-02-01'); // ended long ago
    $schedule('ACTIVE', '2099-01-01', null); // not yet in effect
    DB::table('clinic_operating_hours')->insert(['id' => (string) Str::uuid7(), 'clinic_location_id' => $this->loc, 'day_of_week' => 1, 'opens_at' => '08:00', 'closes_at' => '17:00', 'effective_from' => '2099-01-01', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('clinic_operating_hours')->insert(['id' => (string) Str::uuid7(), 'clinic_location_id' => $this->loc, 'day_of_week' => 1, 'opens_at' => '08:00', 'closes_at' => '17:00', 'effective_until' => '2026-02-01', 'created_at' => now(), 'updated_at' => now()]);

    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'outside_hours');

    // A service mode that ended no longer counts.
    DB::table('doctor_service_modes')->update(['effective_from' => '2026-01-01', 'effective_until' => '2026-02-01']);
    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'no_service_mode');
});

it('builds local times in the location zone across a daylight-saving change', function () {
    DB::table('clinic_locations')->where('id', $this->loc)->update(['timezone' => 'America/New_York']);
    DB::table('doctor_clinic_assignments')->update(['effective_from' => '2026-01-01']);
    DB::table('doctor_service_modes')->update(['effective_from' => '2026-01-01']);
    ($this->hours)([[7, '03:00', '12:00']]); // Sunday 8 March 2026: clocks jump from 02:00 to 03:00

    $this->clock->at = CarbonImmutable::parse('2026-03-08 03:30', 'America/New_York')->utc();
    checkin($this->headers, $this->loc)->assertCreated();
    DB::table('doctor_presence_sessions')->update(['checked_in_at' => $this->clock->at->subDay(), 'current_status' => 'OUT', 'checked_out_at' => $this->clock->at->addMinute(), 'checkout_reason' => 'OTHER', 'checked_out_by' => $this->doc->user_id, 'checked_out_method' => 'DOCTOR_APP']);

    $this->clock->at = CarbonImmutable::parse('2026-03-08 07:00', 'UTC'); // exactly 03:00 EDT (the first minute after the jump): open
    checkin($this->headers, $this->loc)->assertCreated();
});

it('lower-cases ids before using them', function () {
    checkin($this->headers, strtoupper($this->loc))->assertCreated()->assertJsonPath('data.clinic_location_id', $this->loc);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/presence/break/start', ['doctor_id' => strtoupper($this->doc->id), 'clinic_location_id' => strtoupper($this->loc), 'expected_minutes' => 5], $this->w['manager'])->assertOk()->assertJsonPath('data.status', 'ON_BREAK');
    expect(events_of($this->doc->id)->last()->change_method)->toBe('STAFF_CONSOLE');
});

it('sets a clamped lock wait for each change, and puts the caller\'s value back afterwards', function () {
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        if (str_starts_with($q->sql, 'SET LOCAL lock_timeout')) {
            $queries[] = $q->sql;
        }
    });
    $before = DB::selectOne('SHOW lock_timeout')->lock_timeout;

    config(['presence.lock_timeout_ms' => 5]);
    checkin($this->headers, $this->loc)->assertCreated();
    config(['presence.lock_timeout_ms' => 999999]);
    presence_post('break/start', $this->headers, ['expected_minutes' => 5])->assertOk();
    config(['presence.lock_timeout_ms' => 2500]);
    presence_post('break/end', $this->headers)->assertOk();

    // Each change sets the clamped value, then restores what was in force before it (we are inside a test transaction).
    expect(array_values(array_filter($queries, fn ($q) => str_contains($q, "'100ms'") || str_contains($q, "'10000ms'") || str_contains($q, "'2500ms'"))))
        ->toBe(["SET LOCAL lock_timeout = '100ms'", "SET LOCAL lock_timeout = '10000ms'", "SET LOCAL lock_timeout = '2500ms'"])
        ->and(DB::selectOne('SHOW lock_timeout')->lock_timeout)->toBe($before);
});

it('sends no broadcast and no notice for a repeat of a state already reached', function () {
    Queue::fake();
    checkin($this->headers, $this->loc)->assertCreated();
    presence_post('break/start', $this->headers, ['expected_minutes' => 5])->assertOk();
    presence_post('break/end', $this->headers)->assertOk();
    presence_post('unavailable', $this->headers)->assertOk();
    $broadcasts = fn () => Queue::pushed(BroadcastEvent::class)->count();
    $before = $broadcasts();

    checkin($this->headers, $this->loc)->assertStatus(409); // unavailable: not a repeat of IN
    presence_post('unavailable', $this->headers)->assertOk();
    presence_post('available', $this->headers)->assertOk();
    $afterReal = $broadcasts();
    presence_post('available', $this->headers)->assertOk();
    presence_post('break/end', $this->headers)->assertOk();
    checkin($this->headers, $this->loc)->assertOk();

    expect($afterReal)->toBe($before + 1) // only the real change (available) broadcast
        ->and($broadcasts())->toBe($afterReal);
});

it('refuses a check-in while an all-places clinic closure (no location) covers now', function () {
    DB::table('doctor_schedule_exceptions')->insert([
        'id' => (string) Str::uuid7(), 'doctor_profile_id' => $this->doc->id, 'clinic_location_id' => null, 'exception_type' => 'CLINIC_CLOSURE', 'effect' => 'BLOCK', 'priority' => 1,
        'starts_at' => $this->clock->at->subMinutes(10)->toDateTimeString(), 'ends_at' => $this->clock->at->addMinutes(10)->toDateTimeString(), 'requested_by' => $this->doc->user_id, 'status' => 'ACTIVE',
    ]);

    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'clinic_closed');
    expect(DB::table('doctor_presence_sessions')->count())->toBe(0);
});

it('lower-cases scheduled_break_id too', function () {
    $schedule = (string) Str::uuid7();
    DB::table('doctor_schedules')->insert(['id' => $schedule, 'doctor_profile_id' => $this->doc->id, 'name' => 'S', 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'created_by' => $this->doc->user_id]);
    $shift = (string) Str::uuid7();
    DB::table('doctor_schedule_shifts')->insert(['id' => $shift, 'doctor_schedule_id' => $schedule, 'day_of_week' => 1, 'clinic_location_id' => $this->loc, 'doctor_service_mode_id' => $this->w['mode'], 'starts_at_local' => '09:00', 'ends_at_local' => '12:00', 'timezone' => 'Asia/Manila', 'slot_duration_minutes' => 20, 'buffer_minutes' => 0]);
    $break = (string) Str::uuid7();
    DB::table('doctor_schedule_breaks')->insert(['id' => $break, 'doctor_schedule_shift_id' => $shift, 'starts_at_local' => '10:00', 'ends_at_local' => '10:30']);
    checkin($this->headers, $this->loc)->assertCreated();

    presence_post('break/start', $this->headers, ['expected_minutes' => 15, 'scheduled_break_id' => strtoupper($break)])->assertOk();

    expect(json_decode(events_of($this->doc->id)->last()->metadata, true)['scheduled_break_id'])->toBe($break);
});
