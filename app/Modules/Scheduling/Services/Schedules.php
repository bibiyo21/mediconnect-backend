<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Scheduling\Events\SchedulingChanged;
use App\Modules\Scheduling\Exceptions\ScheduleConflict;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Models\DoctorScheduleShift;
use App\Modules\Scheduling\Models\DoctorServiceMode;
use App\Modules\Users\Models\User;
use App\Shared\Services\Outbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recurring weekly schedules (increment 4.3, §7.2, architecture.md §8.1).
 *
 * - A shift's location comes from its service mode, so the two never disagree.
 * - One schedule covers one location, or online only: clinic-scoped access
 *   (AC) can then be checked exactly.
 * - Times are local wall-clock times in the location's time zone; online
 *   shifts use the platform zone unless one is given (Phase 4 plan Q10).
 * - Updates use the `version` optimistic lock (concurrency scenario 4).
 * - Every change is audited and records SchedulingChanged for the refresh.
 */
final class Schedules
{
    public function __construct(private readonly RecordsAudit $audit, private readonly Outbox $outbox) {}

    /**
     * @param  array<string, mixed>  $data  validated payload
     */
    public function create(string $doctorProfileId, array $data, User $actor, ?string $clinicId = null): DoctorSchedule
    {
        return DB::transaction(function () use ($doctorProfileId, $data, $actor, $clinicId) {
            $schedule = new DoctorSchedule;
            $schedule->forceFill([
                'doctor_profile_id' => $doctorProfileId, 'name' => trim((string) $data['name']),
                'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null,
                'status' => $data['status'] ?? 'ACTIVE', 'version' => 1, 'created_by' => $actor->id,
            ])->save();
            $this->replaceShifts($schedule, $data['shifts']);
            $this->assertNoOverlapWithOtherSchedules($schedule);
            $this->outbox->record(new SchedulingChanged($doctorProfileId));
            $this->audit->record('scheduling.schedule_created', 'doctor_schedule', $schedule->id, ['status' => $schedule->status, 'shifts' => count($data['shifts'])], clinicId: $clinicId);

            return $schedule->load('shifts.breaks');
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated payload (any subset); `shifts` replaces every shift
     */
    public function update(DoctorSchedule $schedule, array $data, int $expectedVersion, User $actor, ?string $clinicId = null): DoctorSchedule
    {
        return DB::transaction(function () use ($schedule, $data, $expectedVersion, $actor, $clinicId) {
            /** @var DoctorSchedule $current */
            $current = DoctorSchedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($current->version !== $expectedVersion) {
                throw ScheduleConflict::staleVersion($current->version);
            }
            if ($current->status === 'ARCHIVED') {
                throw ValidationException::withMessages(['status' => ['An archived schedule cannot be changed. Create a new one.']]);
            }
            foreach (['name', 'effective_from', 'effective_until', 'status'] as $field) {
                if (array_key_exists($field, $data)) {
                    $current->setAttribute($field, $field === 'name' ? trim((string) $data[$field]) : $data[$field]);
                }
            }
            $current->forceFill(['version' => $current->version + 1, 'updated_by' => $actor->id])->save();
            if (array_key_exists('shifts', $data)) {
                $current->shifts()->delete(); // breaks cascade; slots keep their rows and are reconciled by the refresh
                $this->replaceShifts($current, $data['shifts']);
            }
            $this->assertNoOverlapWithOtherSchedules($current);
            $this->outbox->record(new SchedulingChanged($current->doctor_profile_id));
            $this->audit->record('scheduling.schedule_updated', 'doctor_schedule', $current->id, ['fields' => array_keys($data), 'version' => $current->version], clinicId: $clinicId);

            return $current->load('shifts.breaks');
        });
    }

    /**
     * Archives; never deletes slots that hold appointments.
     *
     * @return list<string> ids of live appointments that need a new time
     */
    public function archive(DoctorSchedule $schedule, int $expectedVersion, User $actor, ?string $clinicId = null): array
    {
        return DB::transaction(function () use ($schedule, $expectedVersion, $actor, $clinicId) {
            /** @var DoctorSchedule $current */
            $current = DoctorSchedule::whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($current->version !== $expectedVersion) {
                throw ScheduleConflict::staleVersion($current->version);
            }
            $current->forceFill(['status' => 'ARCHIVED', 'version' => $current->version + 1, 'updated_by' => $actor->id])->save();
            $this->outbox->record(new SchedulingChanged($current->doctor_profile_id));
            $this->audit->record('scheduling.schedule_archived', 'doctor_schedule', $current->id, clinicId: $clinicId);

            return $this->affectedAppointments($current);
        });
    }

    /**
     * Live future appointments on slots generated from this schedule's shifts.
     * They are flagged for rescheduling by the refresh (4.6), never cancelled (§8.3).
     *
     * @return list<string>
     */
    public function affectedAppointments(DoctorSchedule $schedule): array
    {
        $ids = DB::table('appointments')
            ->join('doctor_availability_slots', 'doctor_availability_slots.id', '=', 'appointments.doctor_availability_slot_id')
            ->join('doctor_schedule_shifts', 'doctor_schedule_shifts.id', '=', 'doctor_availability_slots.doctor_schedule_shift_id')
            ->where('doctor_schedule_shifts.doctor_schedule_id', $schedule->id)
            ->whereIn('appointments.status', ['PENDING_PAYMENT', 'PENDING_CONFIRMATION', 'CONFIRMED'])
            ->where('appointments.starts_at', '>', now())
            ->pluck('appointments.id')->all();

        return array_values(array_map('strval', $ids));
    }

    /** The single location a schedule covers (null = online); null schedules with no shifts yet. */
    public static function locationOf(DoctorSchedule $schedule): ?string
    {
        return $schedule->shifts()->value('clinic_location_id');
    }

    /** @param list<array<string, mixed>> $shifts */
    private function replaceShifts(DoctorSchedule $schedule, array $shifts): void
    {
        $modes = DoctorServiceMode::whereIn('id', array_column($shifts, 'doctor_service_mode_id'))->get()->keyBy('id');
        $locations = [];
        foreach ($shifts as $i => $shift) {
            /** @var DoctorServiceMode|null $mode */
            $mode = $modes[$shift['doctor_service_mode_id']] ?? null;
            if ($mode === null || $mode->doctor_profile_id !== $schedule->doctor_profile_id || $mode->status !== 'ACTIVE') {
                throw ValidationException::withMessages(["shifts.{$i}.doctor_service_mode_id" => ['Choose one of this doctor\'s active services with a booking mode.']]);
            }
            $locations[$mode->clinic_location_id ?? 'online'] = true;
        }
        if (count($locations) > 1) {
            throw ValidationException::withMessages(['shifts' => ['A schedule covers one clinic location, or online only. Create one schedule per place.']]);
        }
        $this->assertNoOverlapWithin($shifts);

        foreach ($shifts as $i => $data) {
            /** @var DoctorServiceMode $mode checked above */
            $mode = $modes[$data['doctor_service_mode_id']];
            $this->assertShiftFits($data, $mode, $i);
            $shift = new DoctorScheduleShift;
            $shift->forceFill([
                'doctor_schedule_id' => $schedule->id, 'day_of_week' => (int) $data['day_of_week'],
                'clinic_location_id' => $mode->clinic_location_id, 'doctor_service_mode_id' => $mode->id,
                'starts_at_local' => $data['starts_at'], 'ends_at_local' => $data['ends_at'],
                'timezone' => $this->timezoneFor($mode, $data['timezone'] ?? null),
                'slot_duration_minutes' => (int) $data['slot_duration_minutes'], 'buffer_minutes' => (int) ($data['buffer_minutes'] ?? 0),
                'max_appointments' => $data['max_appointments'] ?? null, 'walkin_capacity' => $data['walkin_capacity'] ?? null,
            ])->save();
            foreach ($data['breaks'] ?? [] as $break) {
                $shift->breaks()->forceCreate(['starts_at_local' => $break['starts_at'], 'ends_at_local' => $break['ends_at'], 'label' => $break['label'] ?? null]);
            }
        }
    }

    /** @param array<string, mixed> $shift */
    private function assertShiftFits(array $shift, DoctorServiceMode $mode, int $i): void
    {
        $start = self::minutes((string) $shift['starts_at']);
        $end = self::minutes((string) $shift['ends_at']);
        if ((int) $shift['slot_duration_minutes'] > $end - $start) {
            throw ValidationException::withMessages(["shifts.{$i}.slot_duration_minutes" => ['The appointment length is longer than the shift.']]);
        }
        foreach ($shift['breaks'] ?? [] as $j => $break) {
            if (self::minutes((string) $break['starts_at']) < $start || self::minutes((string) $break['ends_at']) > $end) {
                throw ValidationException::withMessages(["shifts.{$i}.breaks.{$j}" => ['A break must be inside its shift.']]);
            }
        }
        if (($shift['max_appointments'] ?? null) !== null && $mode->service_mode === 'WALK_IN') {
            throw ValidationException::withMessages(["shifts.{$i}.max_appointments" => ['This service takes walk-ins only, so it has no appointment limit.']]);
        }
        if (($shift['walkin_capacity'] ?? null) !== null && $mode->service_mode === 'APPOINTMENT') {
            throw ValidationException::withMessages(["shifts.{$i}.walkin_capacity" => ['This service is by appointment only, so it takes no walk-ins.']]);
        }
    }

    /** @param list<array<string, mixed>> $shifts */
    private function assertNoOverlapWithin(array $shifts): void
    {
        foreach ($shifts as $i => $a) {
            foreach ($shifts as $j => $b) {
                if ($j > $i && (int) $a['day_of_week'] === (int) $b['day_of_week']
                    && self::minutes((string) $a['starts_at']) < self::minutes((string) $b['ends_at'])
                    && self::minutes((string) $b['starts_at']) < self::minutes((string) $a['ends_at'])) {
                    throw ValidationException::withMessages(["shifts.{$j}" => ['This shift overlaps another shift on the same day.']]);
                }
            }
        }
    }

    /**
     * The same doctor cannot be in two places at once: shifts of other ACTIVE
     * schedules with overlapping dates may not overlap in time on the same weekday.
     * Local times are compared, which is exact while all places share a zone.
     */
    private function assertNoOverlapWithOtherSchedules(DoctorSchedule $schedule): void
    {
        if ($schedule->status !== 'ACTIVE') {
            return;
        }
        $mine = DoctorScheduleShift::where('doctor_schedule_id', $schedule->id)->get();
        if ($mine->isEmpty()) {
            return;
        }
        /** @var Collection<int, DoctorScheduleShift> $others */
        $others = DoctorScheduleShift::query()->join('doctor_schedules', 'doctor_schedules.id', '=', 'doctor_schedule_shifts.doctor_schedule_id')
            ->where('doctor_schedules.doctor_profile_id', $schedule->doctor_profile_id)->where('doctor_schedules.status', 'ACTIVE')
            ->where('doctor_schedules.id', '<>', $schedule->id)
            ->where(fn ($q) => $q->whereNull('doctor_schedules.effective_until')->orWhere('doctor_schedules.effective_until', '>=', $schedule->getAttribute('effective_from')))
            ->when($schedule->getAttribute('effective_until') !== null, fn ($q) => $q->where('doctor_schedules.effective_from', '<=', $schedule->getAttribute('effective_until')))
            ->get(['doctor_schedule_shifts.*', 'doctor_schedules.name as schedule_name']);
        foreach ($mine as $a) {
            foreach ($others as $b) {
                if ($a->day_of_week === $b->day_of_week && self::minutes($a->starts_at_local) < self::minutes($b->ends_at_local) && self::minutes($b->starts_at_local) < self::minutes($a->ends_at_local)) {
                    throw ValidationException::withMessages(['shifts' => ['This overlaps the doctor\'s schedule "'.$b->getAttribute('schedule_name').'". A doctor can be in one place at a time.']]);
                }
            }
        }
    }

    private function timezoneFor(DoctorServiceMode $mode, ?string $requested): string
    {
        if ($mode->clinic_location_id !== null) {
            return (string) ClinicLocation::whereKey($mode->clinic_location_id)->value('timezone');
        }

        return $requested ?? (string) config('mediconnect.timezone');
    }

    /** "09:30" or "09:30:00" → minutes after midnight. */
    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }
}
