<?php

namespace App\Modules\Scheduling\Slots;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Loads the generator's inputs for one doctor from PostgreSQL (increment
 * 4.6). Only bookable sources count: an ACTIVE doctor, ACTIVE schedules, ACTIVE
 * service modes on ACTIVE services, and for in-person shifts an ACTIVE
 * location and clinic with an ACTIVE assignment. Everything else yields no slots.
 */
final class AvailabilityInputs
{
    /**
     * @return array{shifts: list<ShiftInput>, hours: array<string, list<array{day: int, opens: string, closes: string, from: string|null, until: string|null}>>, holidays: list<HolidayInput>, exceptions: list<ExceptionInput>}
     */
    public function load(string $doctorId, string $from, string $to): array
    {
        $empty = ['shifts' => [], 'hours' => [], 'holidays' => [], 'exceptions' => []];
        if (! DB::table('doctor_profiles')->where('id', $doctorId)->where('onboarding_status', 'ACTIVE')->exists()) {
            return $empty;
        }

        $rows = DB::table('doctor_schedule_shifts as sh')
            ->join('doctor_schedules as s', 's.id', '=', 'sh.doctor_schedule_id')
            ->join('doctor_service_modes as m', 'm.id', '=', 'sh.doctor_service_mode_id')
            ->join('doctor_services as ds', 'ds.id', '=', 'm.doctor_service_id')
            ->leftJoin('clinic_locations as l', 'l.id', '=', 'sh.clinic_location_id')
            ->leftJoin('clinics as c', 'c.id', '=', 'l.clinic_id')
            ->where('s.doctor_profile_id', $doctorId)->where('s.status', 'ACTIVE')->where('m.status', 'ACTIVE')->where('ds.status', 'ACTIVE')
            ->where('s.effective_from', '<=', $to)->where(fn ($q) => $q->whereNull('s.effective_until')->orWhere('s.effective_until', '>=', $from))
            ->where(fn ($q) => $q->whereNull('sh.clinic_location_id')->orWhere(fn ($w) => $w->where('l.status', 'ACTIVE')->where('c.status', 'ACTIVE')
                ->whereExists(fn ($a) => $a->from('doctor_clinic_assignments')->whereColumn('clinic_location_id', 'sh.clinic_location_id')->where('doctor_profile_id', $doctorId)->where('status', 'ACTIVE'))))
            ->get(['sh.*', 'm.service_mode', 's.effective_from as schedule_from', 's.effective_until as schedule_until', 'l.clinic_id']);

        $breaks = DB::table('doctor_schedule_breaks')->whereIn('doctor_schedule_shift_id', $rows->pluck('id'))->get()->groupBy('doctor_schedule_shift_id');
        $shifts = [];
        foreach ($rows as $r) {
            /** @var list<array{string, string}> $shiftBreaks */
            $shiftBreaks = array_values(($breaks[$r->id] ?? collect())->map(fn ($b) => [(string) $b->starts_at_local, (string) $b->ends_at_local])->all());
            $shiftBreakIds = array_values(($breaks[$r->id] ?? collect())->map(fn ($b) => (string) $b->id)->all());
            $shifts[] = new ShiftInput(
                (string) $r->id, (int) $r->day_of_week, $r->clinic_location_id, (string) $r->doctor_service_mode_id, (string) $r->service_mode,
                (string) $r->starts_at_local, (string) $r->ends_at_local, (string) $r->timezone, (int) $r->slot_duration_minutes, (int) $r->buffer_minutes,
                $r->max_appointments === null ? null : (int) $r->max_appointments, (string) $r->schedule_from, $r->schedule_until === null ? null : (string) $r->schedule_until,
                $shiftBreaks,
                $shiftBreakIds,
            );
        }

        $locations = $rows->pluck('clinic_location_id')->filter()->unique()->values();
        $clinicOf = $rows->filter(fn ($r) => $r->clinic_location_id !== null)->pluck('clinic_id', 'clinic_location_id');
        $hours = [];
        foreach (DB::table('clinic_operating_hours')->whereIn('clinic_location_id', $locations)->get() as $h) {
            $hours[(string) $h->clinic_location_id][] = ['day' => (int) $h->day_of_week, 'opens' => (string) $h->opens_at, 'closes' => (string) $h->closes_at, 'from' => $h->effective_from, 'until' => $h->effective_until];
        }
        $holidays = [];
        foreach (DB::table('clinic_holidays')->whereBetween('date', [$from, $to])->whereIn('clinic_id', $clinicOf->unique()->values())->get() as $h) {
            // A clinic-wide holiday applies to each of its locations this doctor works at.
            $targets = $h->clinic_location_id !== null ? [$h->clinic_location_id] : $clinicOf->filter(fn ($c) => $c === $h->clinic_id)->keys()->all();
            foreach ($targets as $locationId) {
                $holidays[] = new HolidayInput((string) $locationId, (string) $h->date, (bool) $h->is_closed, $h->special_opens_at, $h->special_closes_at);
            }
        }

        $tz = (string) config('mediconnect.timezone');
        $fromUtc = CarbonImmutable::parse($from, $tz)->subDay();
        $toUtc = CarbonImmutable::parse($to, $tz)->addDays(2);
        $exceptions = [];
        $exRows = DB::table('doctor_schedule_exceptions as e')
            ->leftJoin('doctor_service_modes as m', 'm.id', '=', 'e.doctor_service_mode_id')
            ->leftJoin('doctor_services as ds', 'ds.id', '=', 'm.doctor_service_id')
            ->leftJoin('clinic_locations as l', 'l.id', '=', 'e.clinic_location_id')
            ->where('e.doctor_profile_id', $doctorId)->where('e.status', 'ACTIVE')->where('e.starts_at', '<', $toUtc)->where('e.ends_at', '>', $fromUtc)
            ->get(['e.*', 'm.service_mode', 'm.status as mode_status', 'ds.duration_minutes', 'ds.buffer_minutes', 'ds.status as service_status', 'l.timezone as location_timezone']);
        foreach ($exRows as $e) {
            $adds = $e->effect === 'ADD_HOURS';
            if ($adds && ($e->mode_status !== 'ACTIVE' || $e->service_status !== 'ACTIVE')) {
                continue; // added hours for a service that no longer exists add nothing
            }
            $exceptions[] = new ExceptionInput(
                (string) $e->exception_type, (string) $e->effect, $e->clinic_location_id,
                CarbonImmutable::parse((string) $e->starts_at)->getTimestamp(), CarbonImmutable::parse((string) $e->ends_at)->getTimestamp(),
                $adds ? (string) $e->doctor_service_mode_id : null, $adds ? (string) $e->service_mode : null,
                $adds ? (int) $e->duration_minutes : 0, $adds ? (int) $e->buffer_minutes : 0, (string) ($e->location_timezone ?? $tz),
            );
        }

        return ['shifts' => $shifts, 'hours' => $hours, 'holidays' => $holidays, 'exceptions' => $exceptions];
    }
}
