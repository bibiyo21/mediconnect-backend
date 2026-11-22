<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Scheduling\Http\Requests\CreateScheduleExceptionRequest;
use App\Modules\Scheduling\Http\Resources\ScheduleExceptionResource;
use App\Modules\Scheduling\Models\DoctorScheduleException;
use App\Modules\Scheduling\Services\ScheduleAccess;
use App\Modules\Scheduling\Services\ScheduleExceptions;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Schedule exceptions (increment 4.4): the doctor's own (SD) or a clinic's at its locations (AC). */
final class ScheduleExceptionsController
{
    public function __construct(private readonly ScheduleExceptions $exceptions, private readonly ScheduleAccess $access, private readonly ClinicAccess $clinics) {}

    #[QueryParameter('clinic_id', description: 'A clinic you manage: its doctors\' entries at its locations. Without it, your own.', type: 'string', format: 'uuid')]
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $clinicId = $request->query('clinic_id');
        $query = DoctorScheduleException::where('status', 'ACTIVE')->where('ends_at', '>', now())->orderBy('starts_at');
        if (is_string($clinicId) && Str::isUuid($clinicId)) {
            abort_unless($this->clinics->allows($user, 'schedules.view', $clinicId), 403, 'You do not have access to this clinic.');
            $query->whereIn('clinic_location_id', $this->clinics->allowedLocationIds($user, 'schedules.view', $clinicId));
        } else {
            abort_unless($user->can('schedules.view'), 403);
            $own = $this->access->ownProfileId($user) ?? abort(404, 'You do not have a doctor profile.');
            $query->where('doctor_profile_id', $own);
        }

        return ApiResponse::success(ScheduleExceptionResource::collection($query->limit(200)->get()));
    }

    public function store(CreateScheduleExceptionRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validated();
        $doctorId = (string) ($data['doctor_profile_id'] ?? $this->access->ownProfileId($user) ?? abort(422, 'Say which doctor this exception is for (doctor_profile_id).'));
        $clinicId = $this->authorizeAt($user, 'schedules.create', $doctorId, $data['clinic_location_id'] ?? null);
        $result = $this->exceptions->create($doctorId, $data, $user, $clinicId);

        return ApiResponse::success([
            ...(new ScheduleExceptionResource($result['exception']))->resolve($request),
            /** Live appointments in the blocked time: flagged for rescheduling, never cancelled. */
            'affected_appointments' => $result['affected_appointments'],
        ], status: 201);
    }

    public function destroy(Request $request, string $exceptionId): JsonResponse
    {
        $user = $this->user($request);
        $exception = DoctorScheduleException::findOrFail($exceptionId);
        $clinicId = $this->authorizeAt($user, 'schedules.delete', $exception->doctor_profile_id, $exception->clinic_location_id);

        return ApiResponse::success(new ScheduleExceptionResource($this->exceptions->cancel($exception, $user, $clinicId)));
    }

    /** All-places exceptions (no location) belong to the doctor alone. */
    private function authorizeAt(User $user, string $permission, string $doctorId, ?string $locationId): ?string
    {
        abort_unless($this->access->allows($user, $permission, $doctorId, $locationId), 403, 'You cannot manage this doctor\'s schedule here.');

        return $this->access->ownProfileId($user) === $doctorId ? null : $this->access->clinicOf($locationId);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
