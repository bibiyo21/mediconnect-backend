<?php

use App\Modules\Presence\Exceptions\InvalidPresenceTransition;
use App\Modules\Presence\Exceptions\PresenceChangeInProgress;
use App\Modules\Presence\Services\PresenceSessions;
use App\Modules\Users\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Presence concurrency (increment 5.3; the check-out scenario 3 follows in 5.8): real parallel
 * database sessions with committed data, in the pattern of SlotHoldRaceTest. Session 2 plays the
 * competing request inside an open transaction while the application's presence path runs on the
 * default connection. One doctor has at most one open session, and a competing change either waits
 * and loses with a 409 or is refused by the database; never two sessions, never a lost update.
 */

beforeEach(function () {
    config(['database.connections.pgsql_second' => config('database.connections.pgsql'), 'presence.lock_timeout_ms' => 300]);
    fresh_test_database();
    $this->seed(RbacSeeder::class);
    $this->seed(SpecialtySeeder::class);

    $this->location = directory_clinic();
    foreach ([2, 3, 4, 5, 6, 7] as $day) {
        DB::table('clinic_operating_hours')->insert(['id' => (string) Str::uuid7(), 'clinic_location_id' => $this->location->id, 'day_of_week' => $day, 'opens_at' => '08:00', 'closes_at' => '17:00', 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->doctor = directory_doctor('ACTIVE', 'Dr. Ana Santos', $this->location);
    $service = DB::table('doctor_services')->where('doctor_profile_id', $this->doctor->id)->where('consultation_type', 'IN_PERSON')->first();
    DB::table('doctor_service_modes')->insert(['id' => (string) Str::uuid7(), 'doctor_profile_id' => $this->doctor->id, 'clinic_location_id' => $this->location->id, 'doctor_service_id' => $service->id, 'consultation_type' => 'IN_PERSON', 'service_mode' => 'WALK_IN', 'effective_from' => '2026-01-01']);

    pin_clock();
    $this->actor = presence_actor(User::findOrFail($this->doctor->user_id));
    $this->presence = fn () => app(PresenceSessions::class);
});

// Close the competing session first: an open transaction there would block the table rebuild.
afterEach(function () {
    DB::purge('pgsql_second');
    fresh_test_database();
});

/** Session 2 opens a check-in for the doctor inside an open transaction, as the check-in path would. */
function competing_check_in(object $doctor, string $locationId, string $by): Connection
{
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    $second->table('doctor_presence_sessions')->insert([
        'id' => (string) Str::uuid7(), 'doctor_profile_id' => $doctor->id, 'clinic_location_id' => $locationId, 'session_kind' => 'IN_PERSON',
        'current_status' => 'IN', 'checked_in_at' => now(), 'checked_in_by' => $by, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $second;
}

it('presence check-in race: two simultaneous check-ins, exactly one open session', function () {
    $second = competing_check_in($this->doctor, $this->location->id, $this->actor->user->id);

    // While session 2's check-in is uncommitted, ours waits on the one-open-session index and gives up with 409.
    expect(fn () => ($this->presence)()->checkIn($this->actor, $this->doctor->id, $this->location->id, null))->toThrow(PresenceChangeInProgress::class);

    // After session 2 commits, the retry finds that very session already IN here: an idempotent answer with it
    // (not a second session, no new event).
    $second->commit();
    $theirs = DB::table('doctor_presence_sessions')->first();
    $retry = ($this->presence)()->checkIn($this->actor, $this->doctor->id, $this->location->id, null);
    expect($retry->id)->toBe($theirs->id)->and($retry->wasRecentlyCreated)->toBeFalse()
        ->and(DB::table('doctor_presence_sessions')->whereNull('checked_out_at')->count())->toBe(1)
        ->and(DB::table('doctor_presence_events')->count())->toBe(0);

});

it('presence check-in race: the check-in that commits first wins, and the database refuses a second open session', function () {
    $session = ($this->presence)()->checkIn($this->actor, $this->doctor->id, $this->location->id, null);

    $second = DB::connection('pgsql_second');
    expect(fn () => $second->table('doctor_presence_sessions')->insert([
        'id' => (string) Str::uuid7(), 'doctor_profile_id' => $this->doctor->id, 'clinic_location_id' => $this->location->id, 'session_kind' => 'IN_PERSON',
        'current_status' => 'IN', 'checked_in_at' => now(), 'checked_in_by' => $this->actor->user->id, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class, 'uq_one_open_presence_session');
    expect(DB::table('doctor_presence_sessions')->pluck('id')->all())->toBe([$session->id]);
});

it('presence change race: a change waits on the session row lock, then answers 409 instead of overwriting', function () {
    $session = ($this->presence)()->checkIn($this->actor, $this->doctor->id, $this->location->id, null);

    // Session 2 holds the open session row (as another change would, mid-transaction).
    $second = DB::connection('pgsql_second');
    $second->beginTransaction();
    $second->table('doctor_presence_sessions')->where('id', $session->id)->lockForUpdate()->first();

    expect(fn () => ($this->presence)()->startBreak($this->actor, $this->doctor->id, null, 15, null))->toThrow(PresenceChangeInProgress::class)
        ->and(DB::table('doctor_presence_sessions')->where('id', $session->id)->value('current_status'))->toBe('IN');

    // Session 2 goes on break itself and commits; our retry now finds the break already started (a no-op answer,
    // not a second break), and an unavailable toggle is an illegal move from ON_BREAK.
    $second->table('doctor_presence_sessions')->where('id', $session->id)->update(['current_status' => 'ON_BREAK', 'current_break_expected_end_at' => now()->addMinutes(10), 'version' => 2]);
    $second->commit();
    $retry = ($this->presence)()->startBreak($this->actor, $this->doctor->id, null, 15, null);
    expect($retry->current_status->value)->toBe('ON_BREAK')->and($retry->version)->toBe(2)
        ->and(fn () => ($this->presence)()->markUnavailable($this->actor, $this->doctor->id, null, null))->toThrow(InvalidPresenceTransition::class);

    // Once nothing competes, the legal next move works and bumps the version once.
    $after = ($this->presence)()->endBreak($this->actor, $this->doctor->id, null);
    expect($after->current_status->value)->toBe('IN')->and($after->version)->toBe(3)
        ->and(DB::table('doctor_presence_events')->count())->toBe(2); // check-in and break end; the competing raw update wrote no event
});

/** Fires $competitor once, right after the application's open-session lookup (the first statement of a check-in), on the default connection. */
function after_open_session_lookup(Closure $competitor): void
{
    $fired = false;
    DB::listen(function ($query) use (&$fired, $competitor) {
        if (! $fired && str_contains($query->sql, 'from "doctor_presence_sessions"') && str_contains($query->sql, 'for update')) {
            $fired = true;
            $competitor();
        }
    });
}

it('presence check-in race: a competing check-in committed between the lookup and the insert is answered idempotently (unique index branch)', function () {
    after_open_session_lookup(function () {
        $second = competing_check_in($this->doctor, $this->location->id, $this->actor->user->id);
        $second->commit();
    });

    // Our lookup saw nothing, our insert then met the committed session and the unique index: the same place and IN is a repeat.
    $session = ($this->presence)()->checkIn($this->actor, $this->doctor->id, $this->location->id, null);

    expect($session->wasRecentlyCreated)->toBeFalse()
        ->and(DB::table('doctor_presence_sessions')->whereNull('checked_out_at')->count())->toBe(1)
        ->and($session->id)->toBe(DB::table('doctor_presence_sessions')->value('id'))
        ->and(DB::table('doctor_presence_events')->count())->toBe(0);
});

it('presence check-in race: the competing session is at another location, so the loser gets a 409 that hides it from strangers', function () {
    $elsewhere = directory_clinic('Elsewhere Clinic', 'Pasig')->id;
    after_open_session_lookup(function () use ($elsewhere) {
        $second = competing_check_in($this->doctor, $elsewhere, $this->actor->user->id);
        $second->commit();
    });

    // The doctor themself learns where; the event rows of the losing attempt do not exist.
    $thrown = null;
    try {
        ($this->presence)()->checkIn($this->actor, $this->doctor->id, $this->location->id, null);
    } catch (InvalidPresenceTransition $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull()->and($thrown->reasonCode())->toBe('open_session')->and($thrown->errors())->toBe(['clinic_location_id' => [$elsewhere]])
        ->and(DB::table('doctor_presence_sessions')->whereNull('checked_out_at')->count())->toBe(1)
        ->and(DB::table('doctor_presence_events')->count())->toBe(0);
});
