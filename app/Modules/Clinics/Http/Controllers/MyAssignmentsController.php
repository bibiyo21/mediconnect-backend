<?php

namespace App\Modules\Clinics\Http\Controllers;

use App\Modules\Clinics\Http\Requests\EndAssignmentRequest;
use App\Modules\Clinics\Http\Resources\DoctorAssignmentResource;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Clinics\Services\DoctorAssignments;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The doctor's own clinic assignments: accept or decline invitations, end an assignment. */
final class MyAssignmentsController
{
    public function __construct(private readonly DoctorAssignments $assignments) {}

    public function index(Request $request): JsonResponse
    {
        $rows = DoctorClinicAssignment::with(['doctorProfile', 'location.clinic'])
            ->where('doctor_profile_id', $this->profile($request)->id)->where('status', '<>', 'ENDED')
            ->orderBy('status')->orderByDesc('created_at')->get();

        return ApiResponse::success(DoctorAssignmentResource::collection($rows));
    }

    public function accept(Request $request, string $assignmentId): JsonResponse
    {
        return ApiResponse::success(new DoctorAssignmentResource($this->assignments->accept($this->own($request, $assignmentId), $this->user($request))));
    }

    /** Declines a pending invitation or ends an active assignment. */
    public function end(EndAssignmentRequest $request, string $assignmentId): JsonResponse
    {
        return ApiResponse::success(new DoctorAssignmentResource($this->assignments->end($this->own($request, $assignmentId), $this->user($request), (string) $request->validated('reason'))));
    }

    private function own(Request $request, string $assignmentId): DoctorClinicAssignment
    {
        return DoctorClinicAssignment::where('doctor_profile_id', $this->profile($request)->id)->whereKey($assignmentId)->firstOrFail();
    }

    private function profile(Request $request): DoctorProfile
    {
        return DoctorProfile::where('user_id', $this->user($request)->id)->firstOrFail();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
