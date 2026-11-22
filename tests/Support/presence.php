<?php

use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Presence\Enums\PresenceChangeMethod;
use App\Modules\Presence\Jobs\SendBreakReminder;
use App\Modules\Presence\Services\CheckInEligibility;
use App\Modules\Presence\Services\PresenceActor;
use App\Modules\Presence\Services\PresenceSessions;
use App\Modules\Users\Models\User;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * Presence fixtures (increment 5.3). The presence rules read "now" from the injected Clock, so a
 * test pins it to a known local time instead of travelling (which would expire sign-in tokens).
 */

/** A pinned Clock; set ->at to move time. */
final class PinnedClock implements Clock
{
    public function __construct(public CarbonImmutable $at) {}

    public function now(): CarbonImmutable
    {
        return $this->at;
    }
}

/** The next Monday (Asia/Manila) at the given local time, as a UTC instant. Always in the future, so assignments created now are already in effect. */
function next_monday_at(string $time = '10:00'): CarbonImmutable
{
    [$h, $m] = array_map('intval', explode(':', $time));

    return CarbonImmutable::now('Asia/Manila')->next(CarbonImmutable::MONDAY)->setTime($h, $m)->utc();
}

/**
 * Pins the clock of the presence classes only (a contextual binding), so sign-in, MFA and tokens
 * keep the real time; returns the clock so the test can move it.
 */
function pin_clock(?CarbonImmutable $at = null): PinnedClock
{
    $clock = new PinnedClock($at ?? next_monday_at());
    foreach ([CheckInEligibility::class, PresenceSessions::class, SendBreakReminder::class] as $consumer) {
        app()->when($consumer)->needs(Clock::class)->give(fn () => $clock);
    }

    return $clock;
}

/**
 * A clinic open 08:00-17:00 every day, an ACTIVE doctor assigned there with a walk-in service,
 * all through the real APIs.
 *
 * @return array{clinic: string, location: string, manager: array<string, string>, doctor: DoctorProfile, doctorHeaders: array<string, string>, mode: string}
 */
function presence_world(string $licence = '0123456', string $clinicName = 'Santos Family Clinic', string $doctorStatus = 'ACTIVE'): array
{
    [$clinic, $location, $manager] = clinic_with_manager($clinicName);
    test()->putJson("/api/v1/clinics/{$clinic}/locations/{$location}/operating-hours", ['hours' => array_map(fn ($d) => ['day_of_week' => $d, 'opens_at' => '08:00', 'closes_at' => '17:00'], [1, 2, 3, 4, 5, 6, 7])], $manager)->assertOk();
    app('auth')->forgetGuards();
    [$doctor, $doctorHeaders] = verified_doctor($licence, $doctorStatus);
    assign($clinic, $location, $manager, $doctorHeaders, $licence);
    $service = test()->postJson('/api/v1/doctors/me/services', in_person($location, 65000), $doctorHeaders)->assertCreated()->json('data.id');
    $mode = test()->putJson("/api/v1/doctors/me/services/{$service}/mode", ['service_mode' => 'WALK_IN'], $doctorHeaders)->assertOk()->json('data.service_mode_id');
    app('auth')->forgetGuards();

    return ['clinic' => $clinic, 'location' => $location, 'manager' => $manager, 'doctor' => $doctor, 'doctorHeaders' => $doctorHeaders, 'mode' => $mode];
}

/** A PresenceActor for service-level tests. */
function presence_actor(User $user, string $method = 'DOCTOR_APP', ?string $deviceId = null): PresenceActor
{
    return new PresenceActor($user, PresenceChangeMethod::from($method), $deviceId);
}

function checkin(array $headers, string $location, array $extra = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson('/api/v1/presence/check-in', ['clinic_location_id' => $location, ...$extra], $headers);
}

function presence_post(string $path, array $headers, array $body = []): TestResponse
{
    app('auth')->forgetGuards();

    return test()->postJson("/api/v1/presence/{$path}", $body, $headers);
}

function events_of(string $doctorId): Collection
{
    return DB::table('doctor_presence_events')->where('doctor_profile_id', $doctorId)->orderBy('id')->get();
}
