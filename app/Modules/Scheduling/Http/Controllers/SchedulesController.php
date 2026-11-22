<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Clinics\Contracts\ClinicAccess;
use App\Modules\Scheduling\Http\Requests\ScheduleRequest;
use App\Modules\Scheduling\Http\Resources\ScheduleResource;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Models\DoctorServiceMode;
use App\Modules\Scheduling\Services\ScheduleAccess;
use App\Modules\Scheduling\Services\Schedules;
use App\Modules\Users\Models\User;
use App\Shared\Http\ApiResponse;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Weekly schedules (increment 4.3, api-specification.md `/schedules`): the
 * doctor's own (SD) or a clinic's for its locations (AC). Clinic staff read only.
 */
final class SchedulesController
{
    public function __construct(private readonly Schedules $schedules, private readonly ScheduleAccess $access, private readonly ClinicAccess $clinics) {}

    #[QueryParameter('clinic_id', description: 'A clinic you manage: its doctors\' entries at its locations. Without it, your own.', type: 'string', format: 'uuid')]
    #[QueryParameter('doctor_profile_id', description: 'With clinic_id: one doctor only.', type: 'string', format: 'uuid')]
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $clinicId = $request->query('clinic_id');
        $query = DoctorSchedule::with('shifts.breaks')->where('status', '<>', 'ARCHIVED')->orderBy('effective_from');
        if (is_string($clinicId) && Str::isUuid($clinicId)) {
            abort_unless($this->clinics->allows($user, 'schedules.view', $clinicId), 403, 'You do not have access to this clinic.');
            $query->whereHas('shifts', fn ($q) => $q->whereIn('clinic_location_id', $this->clinics->allowedLocationIds($user, 'schedules.view', $clinicId)));
            $doctorId = $request->query('doctor_profile_id');
            $query->when(is_string($doctorId) && Str::isUuid($doctorId), fn ($q) => $q->where('doctor_profile_id', $doctorId));
        } else {
            abort_unless($user->can('schedules.view'), 403);
            $own = $this->access->ownProfileId($user);
            abort_if($own === null, 404, 'You do not have a doctor profile.');
            $query->where('doctor_profile_id', $own);
        }

        return ApiResponse::success(ScheduleResource::collection($query->get()));
    }

    public function show(Request $request, string $scheduleId): JsonResponse
    {
        $schedule = DoctorSchedule::with('shifts.breaks')->findOrFail($scheduleId);
        $this->authorizeAt($request, 'schedules.view', $schedule->doctor_profile_id, Schedules::locationOf($schedule));

        return ApiResponse::success(new ScheduleResource($schedule));
    }

    public function store(ScheduleRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validated();
        $doctorId = (string) ($data['doctor_profile_id'] ?? $this->access->ownProfileId($user) ?? abort(422, 'Say which doctor this schedule is for (doctor_profile_id).'));
        $location = $this->payloadLocation($data);
        $clinicId = $this->authorizeAt($request, 'schedules.create', $doctorId, $location);

        return ApiResponse::success(new ScheduleResource($this->schedules->create($doctorId, $data, $user, $clinicId)), status: 201);
    }

    public function update(ScheduleRequest $request, string $scheduleId): JsonResponse
    {
        $schedule = DoctorSchedule::findOrFail($scheduleId);
        $data = $request->validated();
        $this->authorizeAt($request, 'schedules.update', $schedule->doctor_profile_id, Schedules::locationOf($schedule));
        $clinicId = array_key_exists('shifts', $data)
            ? $this->authorizeAt($request, 'schedules.update', $schedule->doctor_profile_id, $this->payloadLocation($data))
            : $this->access->clinicOf(Schedules::locationOf($schedule));
        unset($data['doctor_profile_id'], $data['version']);

        return ApiResponse::success(new ScheduleResource($this->schedules->update($schedule, $data, $this->version($request), $this->user($request), $clinicId)));
    }

    public function destroy(ScheduleRequest $request, string $scheduleId): JsonResponse
    {
        $schedule = DoctorSchedule::findOrFail($scheduleId);
        $clinicId = $this->authorizeAt($request, 'schedules.delete', $schedule->doctor_profile_id, Schedules::locationOf($schedule));
        $affected = $this->schedules->archive($schedule, $this->version($request), $this->user($request), $clinicId);

        return ApiResponse::success(['id' => $schedule->id, 'status' => 'ARCHIVED', 'affected_appointments' => $affected]);
    }

    /** Checks access; returns the clinic to record on the audit entry (null when the doctor acts on their own). */
    private function authorizeAt(Request $request, string $permission, string $doctorId, ?string $locationId): ?string
    {
        $user = $this->user($request);
        abort_unless($this->access->allows($user, $permission, $doctorId, $locationId), 403, 'You cannot manage this doctor\'s schedule here.');

        return $this->access->ownProfileId($user) === $doctorId ? null : $this->access->clinicOf($locationId);
    }

    /** @param array<string, mixed> $data */
    private function payloadLocation(array $data): ?string
    {
        $modeId = $data['shifts'][0]['doctor_service_mode_id'] ?? null;
        $mode = is_string($modeId) ? DoctorServiceMode::whereKey($modeId)->first() : null;
        if ($mode === null) {
            throw ValidationException::withMessages(['shifts.0.doctor_service_mode_id' => ['Choose one of this doctor\'s active services with a booking mode.']]);
        }

        return $mode->clinic_location_id;
    }

    private function version(ScheduleRequest $request): int
    {
        return $request->expectedVersion() ?? throw ValidationException::withMessages(['version' => ['Send the version you last read, in the If-Match header.']]);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
