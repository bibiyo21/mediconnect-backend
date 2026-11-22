<?php

namespace App\Modules\Clinics\Http\Controllers;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Clinics\Http\Requests\AssignDoctorRequest;
use App\Modules\Clinics\Http\Requests\EndAssignmentRequest;
use App\Modules\Clinics\Http\Resources\DoctorAssignmentResource;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Clinics\Services\ClinicGuard;
use App\Modules\Clinics\Services\DoctorAssignments;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Doctor assignments as a clinic manages them (`doctors.manage_assignments`, clinic-scoped). */
final class ClinicDoctorAssignmentsController
{
    public function __construct(private readonly ClinicGuard $guard, private readonly DoctorAssignments $assignments, private readonly ClinicAccess $access) {}

    public function index(Request $request, string $clinicId): JsonResponse
    {
        $clinic = $this->guard->authorize($request, 'doctors.manage_assignments', $clinicId);
        /** @var User $user */
        $user = $request->user();
        $rows = DoctorClinicAssignment::with(['doctorProfile', 'location.clinic'])
            ->whereIn('clinic_location_id', $this->access->allowedLocationIds($user, 'doctors.manage_assignments', $clinic->id))
            ->when($request->query('include_ended') !== '1', fn ($q) => $q->where('status', '<>', 'ENDED'))
            ->orderBy('status')->orderByDesc('created_at')->get();

        return ApiResponse::success(DoctorAssignmentResource::collection($rows));
    }

    public function store(AssignDoctorRequest $request, string $clinicId): JsonResponse
    {
        $clinic = $this->guard->authorizeAtLocation($request, 'doctors.manage_assignments', $clinicId, (string) $request->validated('clinic_location_id'));
        $location = $clinic->locations()->whereKey((string) $request->validated('clinic_location_id'))->firstOrFail();
        /** @var User $actor */
        $actor = $request->user();
        $assignment = $this->assignments->invite($location, (string) $request->validated('prc_license_number'), $actor);

        return ApiResponse::success(new DoctorAssignmentResource($assignment->load(['doctorProfile', 'location.clinic'])), status: 201);
    }

    public function end(EndAssignmentRequest $request, string $clinicId, string $assignmentId): JsonResponse
    {
        $clinic = $this->guard->authorize($request, 'doctors.manage_assignments', $clinicId);
        $assignment = DoctorClinicAssignment::whereIn('clinic_location_id', $clinic->locations()->select('id'))->whereKey($assignmentId)->firstOrFail();
        $this->guard->authorizeAtLocation($request, 'doctors.manage_assignments', $clinicId, $assignment->clinic_location_id);
        /** @var User $actor */
        $actor = $request->user();

        return ApiResponse::success(new DoctorAssignmentResource($this->assignments->end($assignment, $actor, (string) $request->validated('reason'))));
    }
}
