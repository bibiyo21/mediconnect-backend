<?php

namespace App\Modules\Consultations\Services;

use App\Modules\Consultations\Contracts\CareRelationships;
use App\Modules\Consultations\DTOs\WalkInCareGrant;
use App\Modules\Consultations\Models\CareRelationship;
use App\Modules\Doctors\Models\DoctorProfile;
use App\Modules\Patients\Models\PatientProfile;
use App\Shared\Contracts\Clock;
use LogicException;

/**
 * Grants and revokes care relationships (5.6 plan D1, D2; 5.7 D-A, D-J). The grant is made by the consultation start, as its last write,
 * from the facts the caller passes in (no WalkIns table is read here). The database trigger stays the backstop for source and patient.
 */
final class CareRelationshipService implements CareRelationships
{
    public function __construct(private readonly ConsultationsConfig $config, private readonly Clock $clock) {}

    public function grantForWalkIn(WalkInCareGrant $grant): ?string
    {
        if ($grant->patientProfileId === null) {
            return null;
        }
        // D-16 (defensive): a doctor never becomes the treating doctor of their own patient profile. The policy refuses first.
        $doctorUser = DoctorProfile::query()->whereKey($grant->doctorProfileId)->value('user_id');
        $patientUser = PatientProfile::query()->whereKey($grant->patientProfileId)->value('user_id');
        if ($doctorUser !== null && $doctorUser === $patientUser) {
            throw new LogicException('A doctor cannot be granted care of their own patient profile.');
        }

        $existing = CareRelationship::query()->where('source_type', self::SOURCE_WALK_IN)->where('source_id', $grant->entryId)->first();
        if ($existing !== null) {
            if ($existing->status !== 'ACTIVE' || $existing->doctor_profile_id !== $grant->doctorProfileId) {
                throw new LogicException('The care relationship of this walk-in is not active for this doctor; it is not granted again.');
            }

            return $existing->id;
        }

        // The exact next local midnight plus the extra days (startOfDay + days + 1), in the location's timezone, stored as UTC.
        $accessUntil = $grant->startedAt->setTimezone($grant->timezone)->startOfDay()->addDays($this->config->careAccessDays + 1)->utc();
        $relationship = new CareRelationship;
        $relationship->forceFill([
            'doctor_profile_id' => $grant->doctorProfileId,
            'patient_profile_id' => $grant->patientProfileId,
            'source_type' => self::SOURCE_WALK_IN,
            'source_id' => $grant->entryId,
            'starts_at' => $grant->startedAt->utc(),
            'access_until' => $accessUntil,
            'status' => 'ACTIVE',
        ])->save();

        return $relationship->id;
    }

    public function revokeForSource(string $sourceType, string $sourceId): void
    {
        CareRelationship::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->where('status', 'ACTIVE')
            ->update(['status' => 'REVOKED', 'revoked_at' => $this->clock->now(), 'updated_at' => $this->clock->now()]);
    }
}
