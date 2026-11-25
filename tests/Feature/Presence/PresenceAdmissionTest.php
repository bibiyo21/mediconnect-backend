<?php

use App\Modules\Presence\Contracts\PresenceAdmission;
use App\Modules\Presence\Enums\PresenceStatus;
use App\Modules\Presence\Services\PresenceAdmissionService;
use App\Modules\Scheduling\Contracts\WalkInDay;
use App\Modules\Scheduling\Services\WalkInDayService;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Support\Facades\DB;

/*
 * Increment 5.5a: the doctor's presence for walk-in admission (decisions 168 and 179), through the Presence contract. The state is
 * relative to the location; a check-out earlier today shows as OUT, not NOT_CHECKED_IN.
 */

beforeEach(function () {
    seed_roles();
    $this->seed(SpecialtySeeder::class);
    fake_messages();
    $this->clock = pin_walkin_clock();
    $this->w = walkin_world();
    $this->doctor = $this->w['doctor']->id;
    $this->location = $this->w['location'];
    $this->presence = fn () => app(PresenceAdmission::class);
});

it('says NOT_CHECKED_IN when there is no session, and IN, ON_BREAK (with its end) once there is', function () {
    expect(($this->presence)()->current($this->doctor, $this->location)->status)->toBe(PresenceStatus::NotCheckedIn);

    doctor_in($this->w);
    expect(($this->presence)()->current($this->doctor, $this->location)->status)->toBe(PresenceStatus::In);

    presence_post('break/start', $this->w['doctorHeaders'], ['expected_minutes' => 15])->assertOk();
    $onBreak = ($this->presence)()->current($this->doctor, $this->location);
    expect($onBreak->status)->toBe(PresenceStatus::OnBreak)->and($onBreak->breakEndsAt->toIso8601ZuluString())->toBe($this->clock->now()->addMinutes(15)->toIso8601ZuluString())->and($onBreak->sessionId)->not->toBeNull();

    presence_post('break/end', $this->w['doctorHeaders'])->assertOk();
    presence_post('unavailable', $this->w['doctorHeaders'], ['reason' => 'PHONE_CALL'])->assertOk();
    $away = ($this->presence)()->current($this->doctor, $this->location);
    expect($away->status)->toBe(PresenceStatus::TemporarilyUnavailable)->and($away->breakEndsAt)->toBeNull();
});

it('treats a doctor who is in at another location, or online, as not checked in here', function () {
    doctor_in($this->w);
    $second = wi_second_location($this->w);

    expect(($this->presence)()->current($this->doctor, $second)->status)->toBe(PresenceStatus::NotCheckedIn);
    DB::table('doctor_presence_sessions')->update(['clinic_location_id' => null, 'session_kind' => 'ONLINE']);
    expect(($this->presence)()->current($this->doctor, $this->location)->status)->toBe(PresenceStatus::NotCheckedIn);
});

it('says OUT for a check-out earlier today at this location, but not for yesterday\'s or another location\'s', function () {
    doctor_in($this->w);
    $session = DB::table('doctor_presence_sessions')->where('doctor_profile_id', $this->doctor);
    $close = fn ($at) => $session->update(['current_status' => 'OUT', 'checked_out_at' => $at, 'checkout_reason' => 'END_OF_SHIFT', 'checked_out_method' => 'SYSTEM', 'checked_in_at' => $at->subHour()]);

    $close($this->clock->now()->addMinutes(30)); // today, 10:30 Manila
    expect(($this->presence)()->current($this->doctor, $this->location)->status)->toBe(PresenceStatus::Out);

    $second = wi_second_location($this->w);
    expect(($this->presence)()->current($this->doctor, $second)->status)->toBe(PresenceStatus::NotCheckedIn);

    $this->clock->at = $this->clock->now()->addDay(); // the next morning: the doctor has not checked in yet today
    expect(($this->presence)()->current($this->doctor, $this->location)->status)->toBe(PresenceStatus::NotCheckedIn);
});

it('takes a FOR SHARE lock on the open session, so a competing check-out waits for the registration (decision 168)', function () {
    doctor_in($this->w);
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });

    DB::transaction(fn () => ($this->presence)()->lockShared($this->doctor, $this->location));
    DB::transaction(fn () => ($this->presence)()->current($this->doctor, $this->location));

    $locked = array_values(array_filter($queries, fn ($sql) => str_contains($sql, 'doctor_presence_sessions') && str_contains($sql, 'for share')));
    expect($locked)->toHaveCount(1);
});

it('is registered as the contract the WalkIns module uses, and the WalkInDay contract likewise', function () {
    expect(app(PresenceAdmission::class))->toBeInstanceOf(PresenceAdmissionService::class)
        ->and(app(WalkInDay::class))->toBeInstanceOf(WalkInDayService::class);
});
