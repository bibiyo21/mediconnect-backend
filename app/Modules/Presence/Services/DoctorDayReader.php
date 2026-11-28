<?php

namespace App\Modules\Presence\Services;

use App\Modules\Scheduling\Contracts\DoctorDay;
use App\Modules\Scheduling\DTOs\DoctorDayShift;
use App\Modules\Scheduling\DTOs\TimeWindow;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The doctor's own day, for the dashboard and the clinic picker (increment 5.9-B, decision D-B): the day's shifts (every service mode) and
 * scheduled breaks at each ACTIVE assignment, and whether a check-in would be allowed now. Each location is read for its own local date (its
 * zone's today). Shifts and breaks come from the schedule's DoctorDay contract; the check-in answer comes from the same CheckInEligibility
 * rules the check-in uses. Read-only; no patient data and no appointment ids.
 */
final class DoctorDayReader
{
    public function __construct(
        private readonly DoctorDay $doctorDay,
        private readonly CheckInEligibility $checkIn,
        private readonly Clock $clock,
    ) {}

    /** The app's local date, as Y-m-d, in the market zone (config mediconnect.timezone). */
    public function today(): string
    {
        return $this->clock->now()->setTimezone((string) config('mediconnect.timezone'))->toDateString();
    }

    /**
     * @return array{local_date: string, timezone: string, locations: list<array{clinic_location_id: string, location_name: string, local_date: string, timezone: string, shifts: list<DoctorDayShift>, scheduled_breaks: list<TimeWindow>, check_in: array{allowed: bool, reason_code?: string, opens_at?: CarbonImmutable}}>}
     */
    public function forDoctor(string $doctorId, string $marketDate): array
    {
        $now = $this->clock->now();
        $assignments = DB::table('doctor_clinic_assignments as a')->join('clinic_locations as l', 'l.id', '=', 'a.clinic_location_id')
            ->where('a.doctor_profile_id', $doctorId)->where('a.status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('a.effective_from')->orWhere('a.effective_from', '<=', $now))
            ->where(fn ($q) => $q->whereNull('a.effective_until')->orWhere('a.effective_until', '>', $now))
            ->orderBy('l.name')->orderBy('l.id')->get(['l.id', 'l.name', 'l.timezone']);

        $locations = [];
        foreach ($assignments as $assignment) {
            $locationId = (string) $assignment->id;
            $timezone = (string) $assignment->timezone;
            $localDate = $now->setTimezone($timezone)->toDateString();

            $shifts = $this->doctorDay->forDoctorAtLocation($doctorId, $locationId, $localDate);
            $listed = array_map(fn (DoctorDayShift $shift) => $shift->id, $shifts);
            $refusal = $this->checkIn->probe($doctorId, $locationId);

            $locations[] = [
                'clinic_location_id' => $locationId,
                'location_name' => (string) $assignment->name,
                'local_date' => $localDate,
                'timezone' => $timezone,
                'shifts' => $shifts,
                'scheduled_breaks' => $this->breaks($shifts),
                // opens_at is the next allowed check-in whatever opens it (shift, clinic hours or special hours).
                'check_in' => $refusal === null
                    ? ['allowed' => true]
                    : array_filter([
                        'allowed' => false,
                        'reason_code' => $refusal->reason,
                        'opens_at' => $refusal->opensAt,
                    ], fn ($v) => $v !== null),
            ];
        }

        return ['local_date' => $marketDate, 'timezone' => (string) config('mediconnect.timezone'), 'locations' => $locations];
    }

    /**
     * Every scheduled break of the day's shifts, in start order.
     *
     * @param  list<DoctorDayShift>  $shifts
     * @return list<TimeWindow>
     */
    private function breaks(array $shifts): array
    {
        $breaks = [];
        foreach ($shifts as $shift) {
            array_push($breaks, ...$shift->breaks);
        }
        usort($breaks, fn (TimeWindow $a, TimeWindow $b) => $a->startsAt <=> $b->startsAt);

        return $breaks;
    }
}
