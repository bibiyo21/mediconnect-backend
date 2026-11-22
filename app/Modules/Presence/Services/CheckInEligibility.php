<?php

namespace App\Modules\Presence\Services;

use App\Modules\Presence\Exceptions\CheckInRefused;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The conditions for starting a clinic session (CLAUDE.md §8.2, architecture.md §9.3), read
 * from PostgreSQL, in two steps so that the answer for "no assignment" never depends on where the
 * doctor happens to be checked in:
 *
 *  1. assertAssigned (before any lock): an ACTIVE doctor, an ACTIVE location and clinic, and an ACTIVE
 *     assignment for this doctor at this location;
 *  2. assertCanCheckInNow (after the open-session check): an ACTIVE service mode here, and the time of
 *     day: the clinic is open now, OR now falls in a shift window of this doctor at this location, which
 *     starts `presence.checkin_early_minutes` before the shift and ends at its end (a late doctor, an
 *     evening shift or hours never entered still work; a shift that ended does not by itself).
 *
 * Windows are built in the location's time zone for today and tomorrow (a row or shift ends by 24:00 at
 * the latest, so yesterday cannot reach into today), so hours split at midnight (a row ending 24:00, the next
 * starting 00:00) and an early window that crosses midnight are seen. Wall-clock times are made with
 * createFromFormat in the zone, so a daylight-saving change cannot shift them. A closed holiday refuses; a
 * holiday with special hours replaces that day's hours AND clips the shift windows that day (as the slot
 * generator does); a non-closed holiday without special hours leaves the normal hours. An active CLINIC_CLOSURE
 * exception covering now refuses with `clinic_closed`; a doctor's own leave does not block presence. Presence is independent of the
 * schedule: no shift is needed while the clinic is open. A refusal raises 422 with a `reason_code` and
 * counts DOCTOR_CHECKIN_FAILURES (§29).
 */
final class CheckInEligibility
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @return object{timezone: string, clinic_id: string}
     */
    public function assertAssigned(string $doctorId, string $locationId): object
    {
        $doctor = DB::table('doctor_profiles')->where('id', $doctorId)->first(['onboarding_status']);
        if ($doctor === null || $doctor->onboarding_status !== 'ACTIVE') {
            $this->refuse('doctor_not_active', 'doctor_id', 'Only verified, active doctors can check in.');
        }

        $location = DB::table('clinic_locations as l')->join('clinics as c', 'c.id', '=', 'l.clinic_id')
            ->where('l.id', $locationId)->first(['l.timezone', 'l.clinic_id', 'l.status as location_status', 'c.status as clinic_status']);
        if ($location === null || $location->location_status !== 'ACTIVE' || $location->clinic_status !== 'ACTIVE') {
            $this->refuse('location_not_active', 'clinic_location_id', 'This location is not available for check-in.');
        }

        $now = $this->clock->now();
        $assigned = DB::table('doctor_clinic_assignments')->where('doctor_profile_id', $doctorId)->where('clinic_location_id', $locationId)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $now))->exists();
        if (! $assigned) {
            $this->refuse('no_assignment', 'clinic_location_id', 'You have no active assignment at this location.');
        }

        return (object) ['timezone' => (string) $location->timezone, 'clinic_id' => (string) $location->clinic_id];
    }

    /** @param  object{timezone: string, clinic_id: string}  $location */
    public function assertCanCheckInNow(string $doctorId, string $locationId, object $location): void
    {
        $nowLocal = $this->clock->now()->setTimezone($location->timezone);
        $date = $nowLocal->toDateString();

        $served = DB::table('doctor_service_modes as m')->join('doctor_services as s', 's.id', '=', 'm.doctor_service_id')
            ->where('m.doctor_profile_id', $doctorId)->where('m.clinic_location_id', $locationId)->where('m.status', 'ACTIVE')->where('s.status', 'ACTIVE')
            ->where('m.effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('m.effective_until')->orWhere('m.effective_until', '>=', $date))->exists();
        if (! $served) {
            $this->refuse('no_service_mode', 'clinic_location_id', 'You have no active service at this location. Set up an appointment or walk-in service first.');
        }

        $this->assertNotClosedByException($doctorId, $locationId);
        $this->assertWithinWindow($doctorId, $locationId, $location->clinic_id, $nowLocal);
    }

    /** A clinic closure recorded in this doctor's exceptions, for this location or every place, that covers now. */
    private function assertNotClosedByException(string $doctorId, string $locationId): void
    {
        $now = $this->clock->now();
        $closed = DB::table('doctor_schedule_exceptions')->where('doctor_profile_id', $doctorId)->where('exception_type', 'CLINIC_CLOSURE')->where('effect', 'BLOCK')->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('clinic_location_id')->orWhere('clinic_location_id', $locationId))->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->exists();
        if ($closed) {
            $this->refuse('clinic_closed', 'clinic_location_id', 'The clinic is closed at this time.');
        }
    }

    private function assertWithinWindow(string $doctorId, string $locationId, string $clinicId, CarbonImmutable $nowLocal): void
    {
        $today = $nowLocal->startOfDay();
        $days = [$today, $today->addDay()];
        $early = max(0, (int) config('presence.checkin_early_minutes')) * 60;

        // A location holiday wins over a clinic-wide one on the same date.
        $holidays = [];
        $rows = DB::table('clinic_holidays')->where('clinic_id', $clinicId)->whereBetween('date', [$days[0]->toDateString(), $days[1]->toDateString()])
            ->where(fn ($q) => $q->whereNull('clinic_location_id')->orWhere('clinic_location_id', $locationId))->orderByRaw('clinic_location_id IS NOT NULL')->get();
        foreach ($rows as $row) {
            $holidays[(string) $row->date] = $row;
        }
        if (($holidays[$today->toDateString()]->is_closed ?? false) === true) {
            $this->refuse('closed_holiday', 'clinic_location_id', 'The clinic is closed today.');
        }

        $hours = DB::table('clinic_operating_hours')->where('clinic_location_id', $locationId)->get(['day_of_week', 'opens_at', 'closes_at', 'effective_from', 'effective_until']);
        $shifts = DB::table('doctor_schedule_shifts as sh')->join('doctor_schedules as s', 's.id', '=', 'sh.doctor_schedule_id')->join('doctor_service_modes as m', 'm.id', '=', 'sh.doctor_service_mode_id')
            ->where('s.doctor_profile_id', $doctorId)->where('s.status', 'ACTIVE')->where('m.status', 'ACTIVE')->where('sh.clinic_location_id', $locationId)
            ->get(['sh.day_of_week', 'sh.starts_at_local', 'sh.ends_at_local', 's.effective_from', 's.effective_until']);

        foreach ($days as $day) {
            $date = $day->toDateString();
            $holiday = $holidays[$date] ?? null;
            if ($holiday !== null && $holiday->is_closed) {
                continue; // closed all day: no hours and no shift window that date
            }
            $dow = $day->dayOfWeekIso;
            $inEffect = fn (object $r, string $from, string $until) => ($r->{$from} === null || (string) $r->{$from} <= $date) && ($r->{$until} === null || (string) $r->{$until} >= $date);

            $special = $holiday !== null && $holiday->special_opens_at !== null && $holiday->special_closes_at !== null;
            $open = $special
                ? [[(string) $holiday->special_opens_at, (string) $holiday->special_closes_at]]
                : $hours->filter(fn ($h) => (int) $h->day_of_week === $dow && $inEffect($h, 'effective_from', 'effective_until'))->map(fn ($h) => [(string) $h->opens_at, (string) $h->closes_at])->all();
            foreach ($open as [$opens, $closes]) {
                if ($this->at($day, $opens) <= $nowLocal && $nowLocal < $this->at($day, $closes)) {
                    return;
                }
            }

            foreach ($shifts->filter(fn ($s) => (int) $s->day_of_week === $dow && $inEffect($s, 'effective_from', 'effective_until')) as $shift) {
                $from = $this->at($day, (string) $shift->starts_at_local)->subSeconds($early);
                $until = $this->at($day, (string) $shift->ends_at_local);
                if ($special) {
                    // Special holiday hours: the shift window counts only inside them.
                    $from = max($from, $this->at($day, (string) $holiday->special_opens_at));
                    $until = min($until, $this->at($day, (string) $holiday->special_closes_at));
                }
                if ($from <= $nowLocal && $nowLocal < $until) {
                    return;
                }
            }
        }

        $minutes = intdiv($early, 60);
        $this->refuse('outside_hours', 'clinic_location_id', "The clinic is not open now. You can check in up to {$minutes} minutes before your shift, or during it.");
    }

    /**
     * A local wall-clock time on a local date ($day is that date at 00:00 in the zone): "08:00:00", or "24:00:00"
     * for midnight at the end of the day. Built with createFromFormat in the zone, never by adding seconds, so
     * a daylight-saving change cannot move it.
     */
    private function at(CarbonImmutable $day, string $time): CarbonImmutable
    {
        $date = $day;
        if (str_starts_with($time, '24:')) {
            $date = $day->addDay();
            $time = '00:'.substr($time, 3);
        }
        $at = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $date->toDateString().' '.substr($time.':00', 0, 8), $day->getTimezone());
        if ($at === null) {
            throw new InvalidArgumentException("Not a local time: {$time}");
        }

        return $at;
    }

    private function refuse(string $reason, string $field, string $message): never
    {
        // DOCTOR_CHECKIN_FAILURES (§29): counted from logs; no personal data.
        self::countFailure($reason);

        throw new CheckInRefused($reason, $field, $message);
    }

    public static function countFailure(string $reason): void
    {
        Log::info('Doctor check-in refused', ['metric' => 'DOCTOR_CHECKIN_FAILURES', 'reason' => $reason]);
    }
}
