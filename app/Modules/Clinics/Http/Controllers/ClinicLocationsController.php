<?php

namespace App\Modules\Clinics\Http\Controllers;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Http\Requests\ClinicRules;
use App\Modules\Clinics\Http\Requests\CreateClinicServiceRequest;
use App\Modules\Clinics\Http\Requests\CreateLocationRequest;
use App\Modules\Clinics\Http\Requests\ReplaceHoursRequest;
use App\Modules\Clinics\Http\Requests\UpdateClinicServiceRequest;
use App\Modules\Clinics\Http\Requests\UpdateLocationRequest;
use App\Modules\Clinics\Http\Resources\ClinicManagementResource;
use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Clinics\Models\ClinicService;
use App\Modules\Clinics\Services\ClinicGuard;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Locations, weekly operating hours and services of a clinic (clinic-scoped). */
final class ClinicLocationsController
{
    public function __construct(
        private readonly ClinicGuard $guard,
        private readonly RecordsAudit $audit,
    ) {}

    public function store(CreateLocationRequest $request, string $clinicId): JsonResponse
    {
        $clinic = $this->guard->authorize($request, 'clinics.update', $clinicId);
        $data = $request->validated();
        $data['phone'] = ClinicRules::normalisePhone($data['phone'] ?? null);
        $location = $clinic->locations()->create($data);
        $this->audit->record('clinic.location_created', 'clinic_location', $location->id, ['name' => $location->name], clinicId: $clinic->id);

        return $this->respond($request, $clinic, 201);
    }

    public function update(UpdateLocationRequest $request, string $clinicId, string $locationId): JsonResponse
    {
        $clinic = $this->guard->authorize($request, 'clinics.update', $clinicId);
        $location = $this->location($clinic, $locationId);
        $data = $request->validated();
        if (array_key_exists('phone', $data)) {
            $data['phone'] = ClinicRules::normalisePhone($data['phone']);
        }
        $location->fill($data);
        $changed = array_keys($location->getDirty());
        $location->save();
        if ($changed !== []) {
            $this->audit->record('clinic.location_updated', 'clinic_location', $location->id, ['fields' => $changed], clinicId: $clinic->id);
        }

        return $this->respond($request, $clinic);
    }

    /** Replaces the weekly hours of one location atomically. */
    public function replaceHours(ReplaceHoursRequest $request, string $clinicId, string $locationId): JsonResponse
    {
        $clinic = $this->guard->authorize($request, 'clinics.manage_hours', $clinicId);
        $location = $this->location($clinic, $locationId);

        DB::transaction(function () use ($request, $location, $clinic) {
            $location->operatingHours()->delete();
            foreach ((array) $request->validated('hours') as $h) {
                $location->operatingHours()->create(['day_of_week' => (int) $h['day_of_week'], 'opens_at' => $h['opens_at'], 'closes_at' => $h['closes_at']]);
            }
            $this->audit->record('clinic.hours_replaced', 'clinic_location', $location->id, ['rows' => count((array) $request->validated('hours'))], clinicId: $clinic->id);
        });

        return $this->respond($request, $clinic);
    }

    public function storeService(CreateClinicServiceRequest $request, string $clinicId, string $locationId): JsonResponse
    {
        $clinic = $this->guard->authorize($request, 'clinics.update', $clinicId);
        $location = $this->location($clinic, $locationId);
        if ($location->services()->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $request->input('name')))])->exists()) {
            throw ValidationException::withMessages(['name' => ['This location already offers a service with that name.']]);
        }
        $service = $location->services()->create($request->validated());
        $this->audit->record('clinic.service_created', 'clinic_service', $service->id, ['name' => $service->name], clinicId: $clinic->id);

        return $this->respond($request, $clinic, 201);
    }

    public function updateService(UpdateClinicServiceRequest $request, string $clinicId, string $locationId, string $serviceId): JsonResponse
    {
        $clinic = $this->guard->authorize($request, 'clinics.update', $clinicId);
        $location = $this->location($clinic, $locationId);
        /** @var ClinicService $service */
        $service = $location->services()->whereKey($serviceId)->firstOrFail();
        $service->fill($request->validated());
        $changed = array_keys($service->getDirty());
        $service->save();
        if ($changed !== []) {
            $this->audit->record('clinic.service_updated', 'clinic_service', $service->id, ['fields' => $changed], clinicId: $clinic->id);
        }

        return $this->respond($request, $clinic);
    }

    private function location(Clinic $clinic, string $locationId): ClinicLocation
    {
        /** @var ClinicLocation */
        return $clinic->locations()->whereKey($locationId)->firstOrFail();
    }

    private function respond(Request $request, Clinic $clinic, int $status = 200): JsonResponse
    {
        return ApiResponse::success(new ClinicManagementResource(ClinicManagementController::loaded($clinic->refresh()), $this->guard->abilities($request, $clinic->id)), status: $status);
    }
}
