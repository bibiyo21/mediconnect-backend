<?php

use App\Modules\Presence\Services\CheckInEligibility;
use App\Modules\Presence\Services\DoctorDayReader;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
 * Increment 5.9-B: GET /presence/me/day (decision D-B). The doctor's own day: today's shifts and scheduled breaks at each active
 * assignment, and the advisory check-in answer, which must agree with the check-in itself. Pinned to Monday 10:00 Manila (the
 * pin_clock default), so the Manila day is Mon 2026-11-30 and its UTC instants are fixed.
 */

/** A walk-in Monday shift 09:00-12:00 with a 10:00-10:15 break, written through the schedule API. */
function dd_schedule(array $headers, string $mode, array $breaks = [['starts_at' => '10:00', 'ends_at' => '10:15', 'label' => 'Coffee']]): void
{
    app('auth')->forgetGuards();
    test()->postJson('/api/v1/schedules', ['name' => 'Monday walk-ins', 'effective_from' => CarbonImmutable::now('Asia/Manila')->toDateString(), 'status' => 'ACTIVE',
        'shifts' => [['day_of_week' => 1, 'doctor_service_mode_id' => $mode, 'starts_at' => '09:00', 'ends_at' => '12:00', 'slot_duration_minutes' => 20, 'buffer_minutes' => 5, 'breaks' => $breaks]]], $headers)->assertCreated();
    app('auth')->forgetGuards();
}

function dd_get(array $headers, string $query = ''): TestResponse
{
    app('auth')->forgetGuards();

    return test()->getJson('/api/v1/presence/me/day'.($query === '' ? '' : "?{$query}"), $headers);
}

beforeEach(function () {
    seed_roles();
    fake_messages();
    $this->clock = pin_walkin_clock();
    app()->when(DoctorDayReader::class)->needs(Clock::class)->give(fn () => $this->clock);
    $this->w = presence_world();
    $this->loc = $this->w['location'];
    $this->headers = $this->w['doctorHeaders'];
    dd_schedule($this->headers, $this->w['mode']);
});

it('serves the Manila day in UTC with the shift, the break and an allowed check-in', function () {
    $day = dd_get($this->headers)->assertOk()->json('data');

    expect($day['local_date'])->toBe('2026-11-30')->and($day['timezone'])->toBe('Asia/Manila')
        ->and($day['locations'])->toHaveCount(1);

    $location = $day['locations'][0];
    $breakId = (string) DB::table('doctor_schedule_breaks')->value('id');
    expect($location['clinic_location_id'])->toBe($this->loc)
        ->and($location['location_name'])->toBe(DB::table('clinic_locations')->where('id', $this->loc)->value('name'))
        ->and($location['local_date'])->toBe('2026-11-30')->and($location['timezone'])->toBe('Asia/Manila')
        ->and($location['shifts'])->toBe([['starts_at' => '2026-11-30T01:00:00Z', 'ends_at' => '2026-11-30T04:00:00Z', 'service_mode' => 'WALK_IN']])
        ->and($location['scheduled_breaks'])->toBe([['id' => $breakId, 'starts_at' => '2026-11-30T02:00:00Z', 'ends_at' => '2026-11-30T02:15:00Z']])
        ->and($location['check_in'])->toBe(['allowed' => true]);
});

it('reads each location for its own local date: Honolulu is still Sunday 16:00 when Manila is Monday 10:00', function () {
    // A shift takes its location's zone when it is created (Schedules::timezoneFor), so set both as creation would have.
    DB::table('clinic_locations')->where('id', $this->loc)->update(['timezone' => 'Pacific/Honolulu']);
    DB::table('doctor_schedule_shifts')->update(['timezone' => 'Pacific/Honolulu']);

    $day = dd_get($this->headers)->assertOk()->json('data');
    $location = $day['locations'][0];

    // The market date stays Manila's; the location's own day is Sunday 2026-11-29 in Honolulu, which has no shift (the schedule is Monday).
    expect($day['local_date'])->toBe('2026-11-30')
        ->and($location['local_date'])->toBe('2026-11-29')->and($location['timezone'])->toBe('Pacific/Honolulu')
        ->and($location['shifts'])->toBe([])->and($location['scheduled_breaks'])->toBe([])
        ->and($location['check_in'])->toBe(['allowed' => true]);
});

it('hides the shifts a leave cancels, and a leave does not block presence', function () {
    // The leave must start after the real now (the API rule) and cover the pinned Monday 10:00.
    test()->postJson('/api/v1/schedule-exceptions', ['exception_type' => 'VACATION', 'starts_at' => now()->addMinute()->toIso8601String(), 'ends_at' => now()->addDays(3)->toIso8601String(), 'reason' => 'Family trip'], $this->headers)->assertCreated();
    app('auth')->forgetGuards();

    $location = dd_get($this->headers)->assertOk()->json('data.locations.0');

    expect($location['shifts'])->toBe([])->and($location['check_in'])->toBe(['allowed' => true]);
});

it('applies special hours: the shift is clipped to them, and the check-in opens at the special opening', function () {
    DB::table('clinic_holidays')->insert(['id' => (string) Str::uuid7(), 'clinic_id' => $this->w['clinic'], 'date' => '2026-11-30', 'name' => 'Half day', 'is_closed' => false, 'special_opens_at' => '13:00', 'special_closes_at' => '15:00']);

    $location = dd_get($this->headers)->assertOk()->json('data.locations.0');

    // The next opening is the special hours at 13:00 Manila (05:00 UTC): opens_at names it whatever opens it.
    expect($location['shifts'])->toBe([])
        ->and($location['check_in'])->toBe(['allowed' => false, 'reason_code' => 'outside_hours', 'opens_at' => '2026-11-30T05:00:00Z']);
    // The check-in itself refuses for the same reason (the same rules, no copy).
    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'outside_hours');
});

it('answers the check-in readiness the same as the check-in does, and counts no failure for the read', function () {
    $this->clock->at = CarbonImmutable::parse('2026-11-30 06:00', 'Asia/Manila');
    Log::spy();

    $check = dd_get($this->headers)->assertOk()->json('data.locations.0.check_in');

    // Clinic hours open at 08:00 Manila (00:00 UTC): opens_at names that time even though no shift opens it.
    expect($check)->toBe(['allowed' => false, 'reason_code' => 'outside_hours', 'opens_at' => '2026-11-30T00:00:00Z']);
    Log::shouldNotHaveReceived('info', fn ($message) => $message === 'Doctor check-in refused');

    checkin($this->headers, $this->loc)->assertUnprocessable()->assertJsonPath('reason_code', 'outside_hours');
});

it('names the next opening as opens_at, and never an empty window special hours clip away (m2)', function () {
    // Special hours 07:00-08:40 end before the shift's walk-in window (08:45-12:00) can start: that window is empty, opens nothing, and is not listed.
    DB::table('clinic_holidays')->insert(['id' => (string) Str::uuid7(), 'clinic_id' => $this->w['clinic'], 'date' => '2026-11-30', 'name' => 'Early', 'is_closed' => false, 'special_opens_at' => '07:00', 'special_closes_at' => '08:40']);
    $this->clock->at = CarbonImmutable::parse('2026-11-30 08:41', 'Asia/Manila');

    $probe = (new CheckInEligibility($this->clock))->probe($this->w['doctor']->id, $this->loc);

    // The empty 08:45 window is not a start: the next opening is Tuesday's clinic hours (08:00 Manila = 00:00 UTC).
    expect($probe?->reason)->toBe('outside_hours')->and($probe?->opensAt?->toIso8601ZuluString())->toBe('2026-12-01T00:00:00Z');
    expect(dd_get($this->headers)->assertOk()->json('data.locations.0'))->toMatchArray(['shifts' => [], 'check_in' => ['allowed' => false, 'reason_code' => 'outside_hours', 'opens_at' => '2026-12-01T00:00:00Z']]);
});

it('lists an APPOINTMENT-only shift with its service mode and its break, next to a WALK_IN shift (M2)', function () {
    $service = test()->postJson('/api/v1/doctors/me/services', in_person($this->loc, 65000, 'Appointment consult'), $this->headers)->assertCreated()->json('data.id');
    $apptMode = test()->putJson("/api/v1/doctors/me/services/{$service}/mode", ['service_mode' => 'APPOINTMENT'], $this->headers)->assertOk()->json('data.service_mode_id');
    app('auth')->forgetGuards();
    test()->postJson('/api/v1/schedules', ['name' => 'Monday afternoon', 'effective_from' => CarbonImmutable::now('Asia/Manila')->toDateString(), 'status' => 'ACTIVE',
        'shifts' => [['day_of_week' => 1, 'doctor_service_mode_id' => $apptMode, 'starts_at' => '13:00', 'ends_at' => '15:00', 'slot_duration_minutes' => 20, 'buffer_minutes' => 5,
            'breaks' => [['starts_at' => '13:30', 'ends_at' => '13:45', 'label' => 'Rest']]]]], $this->headers)->assertCreated();
    app('auth')->forgetGuards();
    $apptBreakId = (string) DB::table('doctor_schedule_breaks')->where('starts_at_local', '13:30')->value('id');

    $location = dd_get($this->headers)->assertOk()->json('data.locations.0');

    // Manila 09:00-12:00 is 01:00-04:00 UTC; 13:00-15:00 is 05:00-07:00 UTC. The appointment break 13:30-13:45 is 05:30-05:45 UTC.
    expect($location['shifts'])->toBe([
        ['starts_at' => '2026-11-30T01:00:00Z', 'ends_at' => '2026-11-30T04:00:00Z', 'service_mode' => 'WALK_IN'],
        ['starts_at' => '2026-11-30T05:00:00Z', 'ends_at' => '2026-11-30T07:00:00Z', 'service_mode' => 'APPOINTMENT'],
    ])->and($location['scheduled_breaks'])->toBe([
        ['id' => (string) DB::table('doctor_schedule_breaks')->where('starts_at_local', '10:00')->value('id'), 'starts_at' => '2026-11-30T02:00:00Z', 'ends_at' => '2026-11-30T02:15:00Z'],
        ['id' => $apptBreakId, 'starts_at' => '2026-11-30T05:30:00Z', 'ends_at' => '2026-11-30T05:45:00Z'],
    ]);
});

it('returns exactly the whitelisted keys at every level, with no patient data or appointment ids', function () {
    $body = dd_get($this->headers)->assertOk()->json();

    expect(array_keys($body))->toBe(['success', 'data', 'meta', 'message'])
        ->and(array_keys($body['data']))->toBe(['local_date', 'timezone', 'locations'])
        ->and(array_keys($body['data']['locations'][0]))->toBe(['clinic_location_id', 'location_name', 'local_date', 'timezone', 'shifts', 'scheduled_breaks', 'check_in'])
        ->and(array_keys($body['data']['locations'][0]['shifts'][0]))->toBe(['starts_at', 'ends_at', 'service_mode'])
        ->and(array_keys($body['data']['locations'][0]['scheduled_breaks'][0]))->toBe(['id', 'starts_at', 'ends_at'])
        ->and(array_keys($body['data']['locations'][0]['check_in']))->toBe(['allowed']);
});

it('never shows another doctor: their leave does not change my day, and a doctor_id in the query is ignored', function () {
    $other = wi_second_doctor($this->w);
    test()->postJson('/api/v1/schedule-exceptions', ['exception_type' => 'VACATION', 'starts_at' => now()->addMinute()->toIso8601String(), 'ends_at' => now()->addDays(3)->toIso8601String(), 'reason' => 'Their leave'], $other['headers'])->assertCreated();

    $mine = dd_get($this->headers, 'doctor_id='.$other['doctor']->id)->assertOk()->json('data.locations.0');

    expect($mine['shifts'])->toHaveCount(1)->and($mine['scheduled_breaks'])->toHaveCount(1);
    $theirs = dd_get($other['headers'])->assertOk()->json('data.locations.0');
    expect($theirs['shifts'])->toBe([]);
});

it('refuses a request without a session (401), and a patient with no doctor profile (403)', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/presence/me/day')->assertUnauthorized();

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/presence/me/day', session_for(patient_account()))->assertForbidden();
});

it('serves only today: another date is refused with a field error', function () {
    dd_get($this->headers, 'date=2026-12-01')->assertUnprocessable();
    dd_get($this->headers, 'date=2026-11-30')->assertOk()->assertJsonPath('data.local_date', '2026-11-30');
});

it('contract: the OpenAPI document lists GET /presence/me/day with its date parameter and 200 response', function () {
    $fresh = storage_path('framework/testing/openapi-dayday.json');
    @mkdir(dirname($fresh), 0775, true);
    Artisan::call('scramble:export', ['--path' => $fresh]);

    $doc = json_decode((string) file_get_contents($fresh), true);
    $get = $doc['paths']['/presence/me/day']['get'] ?? null;

    expect($get)->not->toBeNull()
        ->and(collect($get['parameters'] ?? [])->pluck('name')->all())->toContain('date')
        ->and(array_keys($get['responses']))->toContain(200);
});
