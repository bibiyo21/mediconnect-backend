<?php

namespace App\Modules\Consultations\Services;

use App\Modules\Consultations\Contracts\CareRelationships;
use App\Modules\Consultations\Models\CareRelationship;
use App\Shared\Contracts\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Grants and revokes care relationships (5.6 plan D1, D2). The grant is built now and called by 5.7 at consultation start;
 * a no-show or a cancellation happens before start, so revocation is a defensive no-op today and a safety net for 5.8.
 */
final class CareRelationshipService implements CareRelationships
{
    public function __construct(private readonly ConsultationsConfig $config, private readonly Clock $clock) {}

    public function grantForWalkIn(string $entryId, CarbonImmutable $startedAt): ?string
    {
        $entry = DB::table('walkin_queue_entries as e')->join('walkin_queues as q', 'q.id', '=', 'e.walkin_queue_id')
            ->join('clinic_locations as l', 'l.id', '=', 'q.clinic_location_id')
            ->where('e.id', $entryId)
            ->first(['e.patient_profile_id', 'e.assigned_doctor_profile_id', 'q.doctor_profile_id', 'l.timezone']);
        if ($entry === null || $entry->patient_profile_id === null) {
            return null;
        }
        $existing = CareRelationship::query()->where('source_type', self::SOURCE_WALK_IN)->where('source_id', $entryId)->first();
        $doctorId = (string) ($entry->assigned_doctor_profile_id ?? $entry->doctor_profile_id);
        if ($existing !== null) {
            if ($existing->status !== 'ACTIVE' || $existing->doctor_profile_id !== $doctorId) {
                throw new LogicException('The care relationship of this walk-in is not active for this doctor; it is not granted again.');
            }

            return $existing->id;
        }

        // The exact next local midnight plus the extra days (startOfDay + days + 1), in the location's timezone, stored as UTC.
        $accessUntil = $startedAt->setTimezone((string) $entry->timezone)->startOfDay()->addDays($this->config->careAccessDays + 1)->utc();
        $relationship = new CareRelationship;
        $relationship->forceFill([
            'doctor_profile_id' => $doctorId,
            'patient_profile_id' => (string) $entry->patient_profile_id,
            'source_type' => self::SOURCE_WALK_IN,
            'source_id' => $entryId,
            'starts_at' => $startedAt->utc(), // stored as UTC wall time, whatever zone the caller's instant carries
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
