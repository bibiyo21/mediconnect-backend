<?php

namespace App\Modules\Presence\Services;

use App\Modules\Presence\DTOs\CheckInRefusal;
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
 *
 * The rules are written once, as checks that return a CheckInRefusal or null. The assert methods throw and count; probe()
 * returns the same answer as data, counts nothing and never throws (the advisory dashboard, increment 5.9-B).
 */
final class CheckInEligibility
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @return object{timezone: string, clinic_id: string}
     */
    public function assertAssigned(string $doctorId, string $locationId): object
    {
        $result = $this->checkAssigned($doctorId, $locationId);
        if ($result instanceof CheckInRefusal) {
            $this->throwRefusal($result);
        }

        return $result;
    }

    /** @param  object{timezone: string, clinic_id: string}  $location */
    public function assertCanCheckInNow(string $doctorId, string $locationId, object $location): void
    {
        $refusal = $this->checkNow($doctorId, $locationId, $location);
        if ($refusal !== null) {
            $this->throwRefusal($refusal);
        }
    }

    /**
     * Advisory: the same rules as the check-in, in the same order, answered without throwing and without counting a failure.
     * Null means a check-in would be allowed now. The check-in itself still re-checks everything under its lock.
     */
    public function probe(string $doctorId, string $locationId): ?CheckInRefusal
    {
        $result = $this->checkAssigned($doctorId, $locationId);

        return $result instanceof CheckInRefusal ? $result : $this->checkNow($doctorId, $locationId, $result);
    }

    /**
     * The assignment rules: the location (timezone and clinic) when they hold, else the refusal.
     *
     * @return CheckInRefusal|object{timezone: string, clinic_id: string}
     */
    private function checkAssigned(string $doctorId, string $locationId): object
    {
        $doctor = DB::table('doctor_profiles')->where('id', $doctorId)->first(['onboarding_status']);
        if ($doctor === null || $doctor->onboarding_status !== 'ACTIVE') {
            return new CheckInRefusal('doctor_not_active', 'doctor_id', 'Only verified, active doctors can check in.');
        }

        $location = DB::table('clinic_locations as l')->join('clinics as c', 'c.id', '=', 'l.clinic_id')
            ->where('l.id', $locationId)->first(['l.timezone', 'l.clinic_id', 'l.status as location_status', 'c.status as clinic_status']);
        if ($location === null || $location->location_status !== 'ACTIVE' || $location->clinic_status !== 'ACTIVE') {
            return new CheckInRefusal('location_not_active', 'clinic_location_id', 'This location is not available for check-in.');
        }

        $now = $this->clock->now();
        $assigned = DB::table('doctor_clinic_assignments')->where('doctor_profile_id', $doctorId)->where('clinic_location_id', $locationId)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>', $now))->exists();
        if (! $assigned) {
            return new CheckInRefusal('no_assignment', 'clinic_location_id', 'You have no active assignment at this location.');
        }

        return (object) ['timezone' => (string) $location->timezone, 'clinic_id' => (string) $location->clinic_id];
    }

    /** @param  object{timezone: string, clinic_id: string}  $location */
    private function checkNow(string $doctorId, string $locationId, object $location): ?CheckInRefusal
    {
        $nowLocal = $this->clock->now()->setTimezone($location->timezone);
        $date = $nowLocal->toDateString();

        $served = DB::table('doctor_service_modes as m')->join('doctor_services as s', 's.id', '=', 'm.doctor_service_id')
            ->where('m.doctor_profile_id', $doctorId)->where('m.clinic_location_id', $locationId)->where('m.status', 'ACTIVE')->where('s.status', 'ACTIVE')
            ->where('m.effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('m.effective_until')->orWhere('m.effective_until', '>=', $date))->exists();
        if (! $served) {
            return new CheckInRefusal('no_service_mode', 'clinic_location_id', 'You have no active service at this location. Set up an appointment or walk-in service first.');
        }

        return $this->closedByException($doctorId, $locationId) ?? $this->windowRefusal($doctorId, $locationId, $location->clinic_id, $nowLocal);
    }

    /** A clinic closure recorded in this doctor's exceptions, for this location or every place, that covers now. */
    private function closedByException(string $doctorId, string $locationId): ?CheckInRefusal
    {
        $now = $this->clock->now();
        $closed = DB::table('doctor_schedule_exceptions')->where('doctor_profile_id', $doctorId)->where('exception_type', 'CLINIC_CLOSURE')->where('effect', 'BLOCK')->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('clinic_location_id')->orWhere('clinic_location_id', $locationId))->where('starts_at', '<=', $now)->where('ends_at', '>', $now)->exists();

        return $closed ? new CheckInRefusal('clinic_closed', 'clinic_location_id', 'The clinic is closed at this time.') : null;
    }

    private function windowRefusal(string $doctorId, string $locationId, string $clinicId, CarbonImmutable $nowLocal): ?CheckInRefusal
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
            return new CheckInRefusal('closed_holiday', 'clinic_location_id', 'The clinic is closed today.');
        }

        $hours = DB::table('clinic_operating_hours')->where('clinic_location_id', $locationId)->get(['day_of_week', 'opens_at', 'closes_at', 'effective_from', 'effective_until']);
        $shifts = DB::table('doctor_schedule_shifts as sh')->join('doctor_schedules as s', 's.id', '=', 'sh.doctor_schedule_id')->join('doctor_service_modes as m', 'm.id', '=', 'sh.doctor_service_mode_id')
            ->where('s.doctor_profile_id', $doctorId)->where('s.status', 'ACTIVE')->where('m.status', 'ACTIVE')->where('sh.clinic_location_id', $locationId)
            ->get(['sh.id', 'sh.day_of_week', 'sh.starts_at_local', 'sh.ends_at_local', 's.effective_from', 's.effective_until']);

        /** @var list<array{0: CarbonImmutable, 1: string|null}> $starts every non-empty window start, with the shift it belongs to (null: clinic hours) */
        $starts = [];
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
                $from = $this->at($day, $opens);
                $until = $this->at($day, $closes);
                if ($from <= $nowLocal && $nowLocal < $until) {
                    return null;
                }
                if ($from < $until) {
                    $starts[] = [$from, null];
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
                    return null;
                }
                // A window that special hours clip away is empty: it opens nothing, so it is not a start (m2).
                if ($from < $until) {
                    $starts[] = [$from, (string) $shift->id];
                }
            }
        }

        $minutes = intdiv($early, 60);
        // The earliest later start says when a check-in would next be allowed; it names its shift, or null for clinic hours.
        $later = array_values(array_filter($starts, fn (array $start) => $start[0] > $nowLocal));
        usort($later, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $opensAt = $later === [] ? null : $later[0][0]->utc();
        $opensShiftId = $later === [] ? null : $later[0][1];

        return new CheckInRefusal('outside_hours', 'clinic_location_id', "The clinic is not open now. You can check in up to {$minutes} minutes before your shift, or during it.", $opensAt, $opensShiftId);
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

    /** Counts the refusal (DOCTOR_CHECKIN_FAILURES, §29) and throws it; the log line carries no personal data. */
    private function throwRefusal(CheckInRefusal $refusal): never
    {
        self::countFailure($refusal->reason);

        throw new CheckInRefused($refusal->reason, $refusal->field, $refusal->message);
    }

    public static function countFailure(string $reason): void
    {
        Log::info('Doctor check-in refused', ['metric' => 'DOCTOR_CHECKIN_FAILURES', 'reason' => $reason]);
    }
}
