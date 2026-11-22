<?php

namespace App\Modules\Clinics\Http\Controllers;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Http\Requests\CreateHolidayRequest;
use App\Modules\Clinics\Http\Resources\ClinicManagementResource;
use App\Modules\Clinics\Models\ClinicHoliday;
use App\Modules\Clinics\Services\ClinicGuard;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Closures and special hours on given dates (`clinics.manage_hours`, clinic-scoped). */
final class ClinicHolidaysController
{
    public function __construct(
        private readonly ClinicGuard $guard,
        private readonly RecordsAudit $audit,
    ) {}

    public function store(CreateHolidayRequest $request, string $clinicId): JsonResponse
    {
        $locationId = $request->input('clinic_location_id');
        $clinic = is_string($locationId)
            ? $this->guard->authorizeAtLocation($request, 'clinics.manage_hours', $clinicId, $locationId) // a location of this clinic only
            : $this->guard->authorizeWide($request, 'clinics.manage_hours', $clinicId); // whole-clinic closure
        $holiday = $clinic->holidays()->create($request->validated());
        $this->audit->record('clinic.holiday_created', 'clinic_holiday', $holiday->id, ['date' => $holiday->date->toDateString()], clinicId: $clinic->id);

        return ApiResponse::success(new ClinicManagementResource(ClinicManagementController::loaded($clinic), $this->guard->abilities($request, $clinic->id)), status: 201);
    }

    public function destroy(Request $request, string $clinicId, string $holidayId): JsonResponse
    {
        $clinic = $this->guard->authorize($request, 'clinics.manage_hours', $clinicId);
        /** @var ClinicHoliday $holiday */
        $holiday = $clinic->holidays()->whereKey($holidayId)->firstOrFail();
        // A location's holiday needs access there; a whole-clinic one needs clinic-wide access.
        $holiday->clinic_location_id !== null
            ? $this->guard->authorizeAtLocation($request, 'clinics.manage_hours', $clinicId, $holiday->clinic_location_id)
            : $this->guard->authorizeWide($request, 'clinics.manage_hours', $clinicId);
        $holiday->delete();
        $this->audit->record('clinic.holiday_deleted', 'clinic_holiday', $holidayId, ['date' => $holiday->date->toDateString()], clinicId: $clinic->id);

        return ApiResponse::success(new ClinicManagementResource(ClinicManagementController::loaded($clinic), $this->guard->abilities($request, $clinic->id)));
    }
}
