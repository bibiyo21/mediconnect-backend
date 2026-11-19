<?php

namespace App\Modules\Doctors\Services;

use App\Modules\Audit\Contracts\RecordsAudit;
use App\Modules\Clinics\Models\ClinicService;
use App\Modules\Clinics\Models\DoctorClinicAssignment;
use App\Modules\Doctors\Enums\ConsultationType;
use App\Modules\Doctors\Enums\OnboardingStatus;
use App\Modules\Doctors\Exceptions\ServiceNotAllowed;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Doctors\Models\DoctorService;
use App\Modules\Users\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A doctor's services and fees (increment 2.10). The fee always comes from
 * this table, never from a client (CLAUDE.md §32.6). Changes are
 * effective-dated: the old row ends and a replacement starts.
 */
final class DoctorServices
{
    public function __construct(private readonly RecordsAudit $audit) {}

    /** @param array<string, mixed> $data validated input */
    public function create(DoctorProfile $profile, array $data, User $actor): DoctorService
    {
        $this->assertCanOffer($profile);
        $type = ConsultationType::from((string) $data['consultation_type']);
        $locationId = $type === ConsultationType::InPerson ? (string) $data['clinic_location_id'] : null;

        if ($locationId !== null && ! DoctorClinicAssignment::activeAt($profile->id, $locationId)) {
            throw ValidationException::withMessages(['clinic_location_id' => ['You need an active assignment at this clinic location first.']]);
        }
        $clinicServiceId = $data['clinic_service_id'] ?? null;
        if ($clinicServiceId !== null && ClinicService::whereKey($clinicServiceId)->where('clinic_location_id', $locationId)->doesntExist()) {
            throw ValidationException::withMessages(['clinic_service_id' => ['This clinic service is not offered at that location.']]);
        }

        $service = new DoctorService;
        $service->forceFill([
            'doctor_profile_id' => $profile->id, 'clinic_location_id' => $locationId, 'clinic_service_id' => $clinicServiceId,
            'name' => trim((string) $data['name']), 'consultation_type' => $type, 'is_follow_up' => (bool) ($data['is_follow_up'] ?? false),
            'duration_minutes' => (int) $data['duration_minutes'], 'buffer_minutes' => (int) ($data['buffer_minutes'] ?? 0),
            'fee_amount' => (int) $data['fee_amount'], 'currency' => 'PHP', 'is_hmo_eligible' => (bool) ($data['is_hmo_eligible'] ?? false),
            'effective_from' => now(), 'created_by' => $actor->id,
        ]);
        $this->saveUnique($service);
        $this->audit->record('doctor.service_created', 'doctor_service', $service->id, ['fee_amount' => $service->fee_amount, 'consultation_type' => $type->value]);

        return $service;
    }

    /** @param array<string, mixed> $changes validated input */
    public function change(DoctorService $service, array $changes, User $actor): DoctorService
    {
        $this->assertCanOffer($service->doctor_profile_id);

        return DB::transaction(function () use ($service, $changes, $actor) {
            /** @var DoctorService $current */
            $current = DoctorService::whereKey($service->id)->where('status', 'ACTIVE')->lockForUpdate()->firstOrFail();
            $fields = ['name', 'is_follow_up', 'duration_minutes', 'buffer_minutes', 'fee_amount', 'is_hmo_eligible'];
            $next = $current->replicate(['id', 'created_at', 'updated_at']);
            foreach ($fields as $field) {
                if (array_key_exists($field, $changes)) {
                    $next->setAttribute($field, $field === 'name' ? trim((string) $changes[$field]) : $changes[$field]);
                }
            }
            if (! $next->isDirty($fields)) {
                return $current;
            }
            $now = now();
            $current->forceFill(['status' => 'ENDED', 'effective_until' => $now])->save();
            $next->forceFill(['status' => 'ACTIVE', 'effective_from' => $now, 'effective_until' => null, 'replaces_id' => $current->id, 'created_by' => $actor->id]);
            $this->saveUnique($next);
            $this->audit->record('doctor.service_changed', 'doctor_service', $next->id, [
                'replaces' => $current->id, 'fields' => array_values(array_intersect($fields, array_keys($changes))),
                'fee_from' => $current->fee_amount, 'fee_to' => $next->fee_amount,
            ]);

            return $next;
        });
    }

    public function end(DoctorService $service): void
    {
        DoctorService::whereKey($service->id)->where('status', 'ACTIVE')->update(['status' => 'ENDED', 'effective_until' => now()]);
        $this->audit->record('doctor.service_ended', 'doctor_service', $service->id);
    }

    /** Ends every active in-person service at a location (the assignment ended). */
    public function endAtLocation(string $doctorProfileId, string $clinicLocationId): int
    {
        return DoctorService::where('doctor_profile_id', $doctorProfileId)->where('clinic_location_id', $clinicLocationId)
            ->where('status', 'ACTIVE')->update(['status' => 'ENDED', 'effective_until' => now()]);
    }

    /** Only verified or active doctors publish services. */
    private function assertCanOffer(DoctorProfile|string $profile): void
    {
        $status = ($profile instanceof DoctorProfile ? $profile : DoctorProfile::findOrFail($profile))->onboarding_status;
        if (! in_array($status, [OnboardingStatus::Verified, OnboardingStatus::Active], true)) {
            throw new ServiceNotAllowed('Services can be added once your profile is verified.');
        }
    }

    private function saveUnique(DoctorService $service): void
    {
        $duplicate = DoctorService::where('doctor_profile_id', $service->doctor_profile_id)->where('status', 'ACTIVE')
            ->where(fn ($q) => $service->clinic_location_id === null ? $q->whereNull('clinic_location_id') : $q->where('clinic_location_id', $service->clinic_location_id))
            ->whereRaw('lower(name) = lower(?)', [$service->name])->when($service->exists, fn ($q) => $q->whereKeyNot($service->id))->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => ['You already offer a service with this name here.']]);
        }
        try {
            $service->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['You already offer a service with this name here.']]);
        }
    }
}
