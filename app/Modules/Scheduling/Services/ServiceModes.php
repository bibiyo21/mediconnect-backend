<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Scheduling\Enums\ServiceMode;
use App\Modules\Scheduling\Events\SchedulingChanged;
use App\Modules\Scheduling\Models\DoctorServiceMode;
use App\Modules\Users\Models\User;
use App\Shared\Services\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service modes (increment 4.2, §7.1): APPOINTMENT, WALK_IN or BOTH for one
 * doctor service. In-person services carry their location, so a mode set at
 * one clinic never applies to another. Online is appointment-only.
 */
final class ServiceModes
{
    public function __construct(private readonly RecordsAudit $audit, private readonly Outbox $outbox) {}

    /**
     * The active mode for each of these services, keyed by service id.
     *
     * @param  iterable<mixed, string>  $serviceIds
     * @return array<string, DoctorServiceMode>
     */
    public function forServices(iterable $serviceIds): array
    {
        $ids = is_array($serviceIds) ? $serviceIds : iterator_to_array($serviceIds, false);

        return DoctorServiceMode::whereIn('doctor_service_id', $ids)->where('status', 'ACTIVE')->get()->keyBy('doctor_service_id')->all();
    }

    public function set(DoctorService $service, ServiceMode $mode, User $actor, ?string $clinicId = null): DoctorServiceMode
    {
        $online = $service->clinic_location_id === null;
        if ($online && $mode !== ServiceMode::Appointment) {
            throw ValidationException::withMessages(['service_mode' => ['Online consultations are by appointment only.']]);
        }

        return DB::transaction(function () use ($service, $mode, $online, $clinicId) {
            // Serializes concurrent first-time sets for one service (otherwise both would insert).
            DoctorService::whereKey($service->id)->lockForUpdate()->first();
            /** @var DoctorServiceMode|null $current */
            $current = DoctorServiceMode::where('doctor_service_id', $service->id)->where('status', 'ACTIVE')->lockForUpdate()->first();
            if ($current !== null && $current->service_mode === $mode->value) {
                return $current;
            }
            $previous = $current?->service_mode;
            if ($current === null) {
                $current = new DoctorServiceMode;
                $current->forceFill([
                    'doctor_profile_id' => $service->doctor_profile_id, 'clinic_location_id' => $service->clinic_location_id,
                    'doctor_service_id' => $service->id, 'consultation_type' => $online ? 'ONLINE' : 'IN_PERSON',
                    'effective_from' => CarbonImmutable::now((string) config('mediconnect.timezone'))->toDateString(),
                ]);
            }
            $current->forceFill(['service_mode' => $mode->value, 'status' => 'ACTIVE'])->save();
            $this->outbox->record(new SchedulingChanged($service->doctor_profile_id));
            $this->audit->record('scheduling.service_mode_set', 'doctor_service_mode', $current->id, ['from' => $previous, 'to' => $mode->value, 'doctor_service_id' => $service->id], clinicId: $clinicId);

            return $current;
        });
    }

    /** A fee or detail change replaced the service row: keep its mode (Phase 4 plan Q8). Runs inside the change's transaction. */
    public function follow(string $doctorProfileId, string $previousServiceId, string $serviceId): void
    {
        $moved = DoctorServiceMode::where('doctor_service_id', $previousServiceId)->where('status', 'ACTIVE')->update(['doctor_service_id' => $serviceId, 'updated_at' => now()]);
        if ($moved > 0) {
            $this->outbox->record(new SchedulingChanged($doctorProfileId));
        }
    }

    /** @param list<string> $serviceIds services that ended without a replacement */
    public function deactivate(string $doctorProfileId, array $serviceIds): void
    {
        $today = CarbonImmutable::now((string) config('mediconnect.timezone'))->toDateString();
        $ended = DoctorServiceMode::whereIn('doctor_service_id', $serviceIds)->where('status', 'ACTIVE')
            ->update(['status' => 'INACTIVE', 'effective_until' => $today, 'updated_at' => now()]);
        if ($ended > 0) {
            $this->outbox->record(new SchedulingChanged($doctorProfileId));
        }
    }
}
