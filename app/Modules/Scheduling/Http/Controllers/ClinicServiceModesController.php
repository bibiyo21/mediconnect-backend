<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Scheduling\Enums\ServiceMode;
use App\Modules\Scheduling\Http\Requests\SetServiceModeRequest;
use App\Modules\Scheduling\Http\Resources\ServiceModeResource;
use App\Modules\Scheduling\Services\ServiceModes;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Service modes as a clinic manages them (increment 4.2, `schedules.*` at
 * scope AC): only in-person services at this clinic's locations, for doctors
 * with an active assignment there. Online services stay with the doctor.
 */
final class ClinicServiceModesController
{
    public function __construct(private readonly ServiceModes $modes, private readonly ClinicAccess $access) {}

    public function index(Request $request, string $clinicId): JsonResponse
    {
        $clinic = $this->clinic($request, 'schedules.view', $clinicId);
        $doctorId = $request->query('doctor_profile_id');
        $services = $this->services($clinic)->when(is_string($doctorId) && Str::isUuid($doctorId), fn ($q) => $q->where('doctor_profile_id', $doctorId))->get();
        $modes = $this->modes->forServices($services->pluck('id'));

        return ApiResponse::success(ServiceModeResource::collection($services->map(fn (DoctorService $s) => ['service' => $s, 'mode' => $modes[$s->id] ?? null])));
    }

    public function update(SetServiceModeRequest $request, string $clinicId, string $serviceId): JsonResponse
    {
        $clinic = $this->clinic($request, 'schedules.update', $clinicId);
        $service = $this->services($clinic)->whereKey($serviceId)->firstOrFail();
        $mode = $this->modes->set($service, ServiceMode::from((string) $request->validated('service_mode')), $clinic->id);

        return ApiResponse::success(new ServiceModeResource(['service' => $service, 'mode' => $mode]));
    }

    private function clinic(Request $request, string $permission, string $clinicId): Clinic
    {
        $clinic = Clinic::findOrFail($clinicId);
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->access->allows($user, $permission, $clinic->id), 403, 'You do not have access to this clinic.');

        return $clinic;
    }

    /** @return Builder<DoctorService> */
    private function services(Clinic $clinic)
    {
        $locations = $clinic->locations()->select('id');
        $assigned = DoctorClinicAssignment::where('status', 'ACTIVE')->whereIn('clinic_location_id', $locations)->select('doctor_profile_id');

        return DoctorService::with('location')->where('status', 'ACTIVE')->whereIn('clinic_location_id', $locations)
            ->whereIn('doctor_profile_id', $assigned)->orderBy('doctor_profile_id')->orderBy('name');
    }
}
