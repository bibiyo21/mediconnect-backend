<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Scheduling\Models\DoctorScheduleShift;
use App\Shared\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * Public weekly summary of an ACTIVE doctor's hours (increment 4.3): where,
 * which days and which hours. No caps, buffers or internal ids. Advisory:
 * bookable times come from availability (§11.6).
 */
final class PublicSchedulesController
{
    public function __invoke(string $doctorId): JsonResponse
    {
        $doctor = DoctorProfile::where('onboarding_status', 'ACTIVE')->findOrFail($doctorId);
        $today = CarbonImmutable::now((string) config('mediconnect.timezone'))->toDateString();
        $shifts = DoctorScheduleShift::query()
            ->join('doctor_schedules', 'doctor_schedules.id', '=', 'doctor_schedule_shifts.doctor_schedule_id')
            ->join('doctor_service_modes', 'doctor_service_modes.id', '=', 'doctor_schedule_shifts.doctor_service_mode_id')
            ->leftJoin('clinic_locations', 'clinic_locations.id', '=', 'doctor_schedule_shifts.clinic_location_id')
            ->leftJoin('clinics', 'clinics.id', '=', 'clinic_locations.clinic_id')
            ->where('doctor_schedules.doctor_profile_id', $doctor->id)->where('doctor_schedules.status', 'ACTIVE')
            ->where('doctor_service_modes.status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('doctor_schedules.effective_until')->orWhere('doctor_schedules.effective_until', '>=', $today))
            ->where(fn ($q) => $q->whereNull('clinic_locations.id')->orWhere(fn ($w) => $w->where('clinic_locations.status', 'ACTIVE')->where('clinics.status', 'ACTIVE')))
            ->orderBy('doctor_schedule_shifts.day_of_week')->orderBy('doctor_schedule_shifts.starts_at_local')
            ->get(['doctor_schedule_shifts.*', 'doctor_service_modes.consultation_type', 'doctor_service_modes.service_mode', 'clinics.name as clinic_name', 'clinic_locations.name as location_name', 'doctor_schedules.effective_from as schedule_from']);

        $places = $shifts->groupBy(fn ($s) => $s->clinic_location_id ?? 'online')->map(function ($group) {
            /** @var DoctorScheduleShift $first */
            $first = $group->first();

            return [
                'clinic_location_id' => $first->clinic_location_id,
                'clinic_name' => $first->getAttribute('clinic_name'),
                'location_name' => $first->getAttribute('location_name'),
                'consultation_type' => $first->getAttribute('consultation_type'),
                'timezone' => $first->timezone,
                'hours' => $group->map(fn ($s) => [
                    'day_of_week' => $s->day_of_week, 'starts_at' => substr($s->starts_at_local, 0, 5), 'ends_at' => substr($s->ends_at_local, 0, 5),
                    'service_mode' => $s->getAttribute('service_mode'), 'from' => (string) $s->getAttribute('schedule_from'),
                ])->unique(fn ($h) => $h['day_of_week'].$h['starts_at'].$h['ends_at'])->values()->all(),
            ];
        })->values();

        return ApiResponse::success($places, meta: ['note' => 'Regular hours. Exceptions such as leave and holidays apply; check availability for bookable times.']);
    }
}
