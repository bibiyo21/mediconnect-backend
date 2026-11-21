<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Scheduling\Events\SchedulingChanged;
use App\Modules\Scheduling\Models\DoctorScheduleException;
use App\Modules\Scheduling\Models\DoctorServiceMode;
use App\Modules\Users\Models\User;
use App\Shared\Services\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Schedule exceptions (increment 4.4, §7.3, D-11). Priority is derived from
 * the type, highest first:
 *   1 clinic closure and public holiday,
 *   2 doctor leave (vacation, sick, emergency),
 *   3 temporary unavailability (and requested changes that block),
 *   4 special working hours (and requested changes that add hours),
 *   5 the recurring schedule itself.
 * A blocking exception never cancels appointments: overlapping live ones are
 * flagged `needs_rescheduling` and get a rescheduling task (§8.3).
 */
final class ScheduleExceptions
{
    public const PRIORITY = [
        'CLINIC_CLOSURE' => 1, 'PUBLIC_HOLIDAY' => 1,
        'VACATION' => 2, 'SICK_LEAVE' => 2, 'EMERGENCY_LEAVE' => 2,
        'TEMPORARY_UNAVAILABILITY' => 3,
        'SPECIAL_HOURS' => 4,
    ];

    private const LIVE = ['PENDING_PAYMENT', 'PENDING_CONFIRMATION', 'CONFIRMED', 'CHECKED_IN'];

    public function __construct(private readonly RecordsAudit $audit, private readonly Outbox $outbox) {}

    /**
     * @param  array<string, mixed>  $data  validated payload
     * @return array{exception: DoctorScheduleException, affected_appointments: list<string>}
     */
    public function create(string $doctorProfileId, array $data, User $actor, ?string $clinicId = null): array
    {
        $type = (string) $data['exception_type'];
        $effect = $type === 'SPECIAL_HOURS' ? 'ADD_HOURS' : (string) ($data['effect'] ?? 'BLOCK');
        if ($effect === 'ADD_HOURS' && ! in_array($type, ['SPECIAL_HOURS', 'DOCTOR_REQUESTED_CHANGE', 'CLINIC_REQUESTED_CHANGE'], true)) {
            throw ValidationException::withMessages(['effect' => ['Only special hours or a requested change can add time.']]);
        }
        $locationId = $data['clinic_location_id'] ?? null;
        $modeId = null;
        if ($effect === 'ADD_HOURS') {
            $requestedMode = $data['doctor_service_mode_id'] ?? null;
            $mode = is_string($requestedMode) && $requestedMode !== ''
                ? DoctorServiceMode::whereKey($requestedMode)->where('doctor_profile_id', $doctorProfileId)->where('status', 'ACTIVE')->first()
                : null;
            if ($mode === null || $mode->service_mode === 'WALK_IN') {
                throw ValidationException::withMessages(['doctor_service_mode_id' => ['Choose the appointment service these extra hours are for.']]);
            }
            if ($mode->clinic_location_id !== $locationId) {
                throw ValidationException::withMessages(['clinic_location_id' => ['Extra hours are at the place of their service.']]);
            }
            $modeId = $mode->id;
        }
        $priority = self::PRIORITY[$type] ?? ($effect === 'ADD_HOURS' ? 4 : 3);
        $startsAt = CarbonImmutable::parse((string) $data['starts_at']);
        $endsAt = CarbonImmutable::parse((string) $data['ends_at']);

        return DB::transaction(function () use ($doctorProfileId, $locationId, $modeId, $type, $effect, $priority, $startsAt, $endsAt, $data, $actor, $clinicId) {
            $exception = new DoctorScheduleException;
            $exception->forceFill([
                'doctor_profile_id' => $doctorProfileId, 'clinic_location_id' => $locationId, 'doctor_service_mode_id' => $modeId,
                'exception_type' => $type, 'effect' => $effect, 'priority' => $priority,
                'starts_at' => $startsAt, 'ends_at' => $endsAt, 'reason' => $data['reason'] ?? null, 'requested_by' => $actor->id, 'status' => 'ACTIVE',
            ])->save();
            $affected = $effect === 'BLOCK' ? $this->flagAffected($doctorProfileId, $locationId, $startsAt, $endsAt) : [];
            $this->outbox->record(new SchedulingChanged($doctorProfileId));
            // The reason may be personal (for example sick leave): it is stored, not copied into the audit trail.
            $this->audit->record('scheduling.exception_created', 'doctor_schedule_exception', $exception->id, ['type' => $type, 'effect' => $effect, 'affected' => count($affected)], clinicId: $clinicId);

            return ['exception' => $exception, 'affected_appointments' => $affected];
        });
    }

    public function cancel(DoctorScheduleException $exception, User $actor, ?string $clinicId = null): DoctorScheduleException
    {
        return DB::transaction(function () use ($exception, $actor, $clinicId) {
            /** @var DoctorScheduleException $current */
            $current = DoctorScheduleException::whereKey($exception->id)->lockForUpdate()->firstOrFail();
            if ($current->status === 'CANCELLED') {
                return $current;
            }
            $current->forceFill(['status' => 'CANCELLED', 'cancelled_by' => $actor->id, 'cancelled_at' => now()])->save();
            $this->outbox->record(new SchedulingChanged($current->doctor_profile_id));
            $this->audit->record('scheduling.exception_cancelled', 'doctor_schedule_exception', $current->id, ['type' => $current->exception_type], clinicId: $clinicId);

            return $current;
        });
    }

    /**
     * Flags live appointments inside the blocked window; never cancels them.
     *
     * @return list<string>
     */
    private function flagAffected(string $doctorProfileId, ?string $locationId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ids = DB::table('appointments')->where('doctor_profile_id', $doctorProfileId)
            ->when($locationId !== null, fn ($q) => $q->where('clinic_location_id', $locationId))
            ->whereIn('status', self::LIVE)->where('starts_at', '<', $to)->where('ends_at', '>', $from)->where('starts_at', '>', now())
            ->lockForUpdate()->pluck('id')->map(fn ($id) => (string) $id)->all();
        if ($ids === []) {
            return [];
        }
        DB::table('appointments')->whereIn('id', $ids)->update(['needs_rescheduling' => true, 'updated_at' => now()]);
        foreach ($ids as $id) {
            DB::table('rescheduling_tasks')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'appointment_id' => $id, 'reason' => 'EXCEPTION_ADDED', 'status' => 'OPEN', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return array_values($ids);
    }
}
