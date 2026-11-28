<?php

namespace App\Modules\Presence\Services;

use App\Modules\Appointments\Contracts\CheckOutAppointments;
use App\Modules\Clinics\DTOs\WalkInPolicy;
use App\Modules\Scheduling\Contracts\WalkInDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Open in-person sessions that the automatic check-out (increment 5.8, D-L) should close now. Two triggers, both on the location's own clock:
 *  (a) the session was checked in on an earlier local date; or
 *  (b) today, the time is past the latest of today's clinic closing, the doctor's last shift end there and the end of the doctor's last live
 *      appointment there today (5.8 V-M1: a special-hours block past the clinic close is work, not a forgotten session), plus the grace minutes.
 * A session with no closing time today (the clinic is shut, the doctor has no shift and no booking) is not due by (b); (a) covers it the next day.
 * An assignment that has ended still counts (m1): the session is open, so it must still be closed; its grace is the default.
 * Only ids and times are returned. Whether a candidate may really be checked out is decided under lock by the job.
 */
final class AutoCheckOutCandidates
{
    public function __construct(private readonly WalkInDay $days, private readonly CheckOutAppointments $appointments) {}

    /** @return list<array{session_id: string, doctor_profile_id: string, clinic_location_id: string}> */
    public function due(CarbonImmutable $now): array
    {
        $rows = DB::table('doctor_presence_sessions as s')
            ->join('clinic_locations as l', 'l.id', '=', 's.clinic_location_id')
            ->leftJoin('doctor_clinic_assignments as a', fn ($join) => $join->on('a.doctor_profile_id', '=', 's.doctor_profile_id')
                ->on('a.clinic_location_id', '=', 's.clinic_location_id')->where('a.status', '=', 'ACTIVE'))
            ->whereNull('s.checked_out_at')
            ->where('s.session_kind', 'IN_PERSON')
            ->orderBy('s.id')
            ->get(['s.id', 's.doctor_profile_id', 's.clinic_location_id', 's.checked_in_at', 'l.timezone', 'a.walkin_policy']);

        $due = [];
        foreach ($rows as $row) {
            $timezone = is_string($row->timezone) && $row->timezone !== '' ? $row->timezone : (string) config('mediconnect.timezone');
            $localNow = $now->setTimezone($timezone);
            $checkedInDate = CarbonImmutable::parse((string) $row->checked_in_at)->setTimezone($timezone)->toDateString();

            $doctorId = (string) $row->doctor_profile_id;
            $locationId = (string) $row->clinic_location_id;
            if ($checkedInDate < $localNow->toDateString() || $this->pastClosing($doctorId, $locationId, $row->walkin_policy, $localNow)) {
                $due[] = ['session_id' => (string) $row->id, 'doctor_profile_id' => $doctorId, 'clinic_location_id' => $locationId];
            }
        }

        return $due;
    }

    private function pastClosing(string $doctorId, string $locationId, mixed $storedPolicy, CarbonImmutable $localNow): bool
    {
        $facts = $this->days->forDoctorAtLocation($doctorId, $locationId, $localNow->toDateString(), anyClinicService: true);
        $dayStart = $localNow->startOfDay()->utc();
        $lastBooking = $this->appointments->lastEndsAt($doctorId, $locationId, $dayStart, $dayStart->addDay());
        $closing = collect([$facts->clinicClosesAt, $facts->lastShiftEndsAt, $lastBooking])->filter()->sortByDesc(fn (CarbonImmutable $t) => $t->getTimestamp())->first();
        if ($closing === null) {
            return false;
        }

        return $localNow->utc()->gt($closing->addMinutes($this->graceMinutes($storedPolicy)));
    }

    /** The auto check-out grace for one doctor at one location (Q8, default 60), read from the ACTIVE assignment's walk-in policy. */
    public function graceFor(string $doctorId, string $locationId): int
    {
        $stored = DB::table('doctor_clinic_assignments')->where('doctor_profile_id', $doctorId)->where('clinic_location_id', $locationId)
            ->where('status', 'ACTIVE')->value('walkin_policy');

        return $this->graceMinutes($stored);
    }

    /** The auto check-out grace in minutes from the assignment's walk-in policy (Q8, default 60; an ended assignment has none, so the default). */
    private function graceMinutes(mixed $stored): int
    {
        $policy = is_string($stored) ? json_decode($stored, true) : $stored;

        return WalkInPolicy::fromStored(is_array($policy) ? $policy : null)->autoCheckoutGraceMinutes;
    }
}
