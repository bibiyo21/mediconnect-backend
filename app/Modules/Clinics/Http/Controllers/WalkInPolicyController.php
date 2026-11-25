<?php

namespace App\Modules\Clinics\Http\Controllers;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\DTOs\WalkInPolicy;
use App\Modules\Clinics\Enums\AssignmentStatus;
use App\Modules\Clinics\Exceptions\InvalidAssignmentState;
use App\Modules\Clinics\Http\Requests\UpdateWalkInPolicyRequest;
use App\Modules\Clinics\Http\Resources\WalkInPolicyResource;
use App\Modules\Clinics\Models\Clinic;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Clinics\Services\ClinicGuard;
use App\Modules\Scheduling\Contracts\WalkInDay;
use App\Shared\Contracts\Clock;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A doctor's walk-in settings at a location (increment 5.5a, decisions 97, 100, 151). `clinics.update` at the
 * assignment's location (a member restricted to another location is refused). Patient-app registration is off until a clinic turns it on (A1).
 */
final class WalkInPolicyController
{
    public function __construct(
        private readonly ClinicGuard $guard,
        private readonly WalkInDay $day,
        private readonly RecordsAudit $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * The walk-in settings.
     *
     * Shows the stored settings (defaults for any key never set) and the capacity today's schedule derives for this doctor here.
     */
    public function show(Request $request, string $clinicId, string $assignmentId): JsonResponse
    {
        $assignment = $this->assignment($request, $clinicId, $assignmentId);

        return ApiResponse::success(new WalkInPolicyResource($this->view($assignment, WalkInPolicy::fromStored($assignment->walkin_policy))));
    }

    /** Changes the settings sent (partial). Unknown keys are refused (422). The change is audited with the keys and their old and new values. */
    public function update(UpdateWalkInPolicyRequest $request, string $clinicId, string $assignmentId): JsonResponse
    {
        $assignment = $this->assignment($request, $clinicId, $assignmentId);
        if ($assignment->status === AssignmentStatus::Ended) {
            throw InvalidAssignmentState::is($assignment->status);
        }

        $changes = $request->changes();
        // The row is locked while the settings are read, merged and written, so two simultaneous changes to different keys both survive.
        $after = DB::transaction(function () use ($assignment, $changes, $clinicId): WalkInPolicy {
            $locked = DB::table('doctor_clinic_assignments')->where('id', $assignment->id)->lockForUpdate()->first(['walkin_policy', 'status']);
            if ($locked === null || $locked->status === 'ENDED') {
                throw InvalidAssignmentState::is(AssignmentStatus::Ended);
            }
            $stored = is_string($locked->walkin_policy) ? json_decode($locked->walkin_policy, true) : null;
            $before = WalkInPolicy::fromStored(is_array($stored) ? $stored : null);
            $after = $before->with($changes);
            // Direct update: a policy change is not a directory change, so it must not queue a search reindex.
            DB::table('doctor_clinic_assignments')->where('id', $assignment->id)->update(['walkin_policy' => json_encode($after->toStored()), 'updated_at' => now()]);
            $changed = [];
            foreach ($after->toStored() as $key => $value) {
                if ($before->toStored()[$key] !== $value) {
                    $changed[$key] = [$before->toStored()[$key], $value];
                }
            }
            $this->audit->record('walkins.policy_updated', 'doctor_clinic_assignment', $assignment->id, $changed, clinicId: $clinicId);

            return $after;
        });

        return ApiResponse::success(new WalkInPolicyResource($this->view($assignment->refresh(), $after)));
    }

    private function assignment(Request $request, string $clinicId, string $assignmentId): DoctorClinicAssignment
    {
        $clinic = Clinic::findOrFail($clinicId);
        $assignment = DoctorClinicAssignment::whereIn('clinic_location_id', $clinic->locations()->select('id'))->whereKey($assignmentId)->firstOrFail();
        $this->guard->authorizeAtLocation($request, 'clinics.update', $clinicId, $assignment->clinic_location_id);

        return $assignment;
    }

    /** @return array{assignment_id: string, clinic_location_id: string, doctor_profile_id: string, policy: WalkInPolicy, derived_capacity_today: int, local_date: string, timezone: string} */
    private function view(DoctorClinicAssignment $assignment, WalkInPolicy $policy): array
    {
        $timezone = (string) $assignment->location->timezone;
        $date = $this->clock->now()->setTimezone($timezone)->toDateString();
        $facts = $this->day->forDoctorAtLocation($assignment->doctor_profile_id, $assignment->clinic_location_id, $date, anyClinicService: true);

        return [
            'assignment_id' => $assignment->id,
            'clinic_location_id' => $assignment->clinic_location_id,
            'doctor_profile_id' => $assignment->doctor_profile_id,
            'policy' => $policy,
            'derived_capacity_today' => $facts->capacity,
            'local_date' => $date,
            'timezone' => $timezone,
        ];
    }
}
