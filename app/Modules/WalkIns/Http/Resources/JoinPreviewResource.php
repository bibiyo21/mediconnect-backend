<?php

namespace App\Modules\WalkIns\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a patient may know before joining a walk-in queue. No counts and no remaining places. The estimate is a range or nothing; the
 * fee is the listed consultation fee, paid at the clinic, and the final charge is the clinic's to set.
 *
 * @property array<string, mixed> $resource
 */
final class JoinPreviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            'doctor_service_id' => (string) $p['doctor_service_id'],
            'service_name' => (string) $p['service_name'],
            /** @var string|null */
            'doctor_name' => $p['doctor_name'],
            'clinic_location_id' => (string) $p['clinic_location_id'],
            'location_name' => (string) $p['location_name'],
            'timezone' => (string) $p['timezone'],
            /** The location's local date (Y-m-d). */
            'local_date' => (string) $p['local_date'],
            /** NOT_STARTED (the doctor has not checked in), OPEN, PAUSED or CLOSED. Drives the Join button (E8). */
            'state' => (string) $p['state'],
            /** @var 'paused'|'closed'|'cutoff_passed'|'clinic_closed_today'|'no_walkin_hours_today'|'doctor_unavailable'|'doctor_checked_out'|'doctor_not_active'|'location_not_active'|null Why the state is PAUSED or CLOSED; null when OPEN or NOT_STARTED. */
            'state_reason_code' => $p['state_reason_code'],
            /** Whether the app can register this patient now. */
            'accepts_remote_registration' => (bool) $p['accepts_remote_registration'],
            /** @var string|null Why not: doctor_not_active, location_not_active, remote_registration_off, mobile_not_verified, queue_not_started, doctor_unavailable, doctor_checked_out, paused, closed, cutoff_passed, clinic_closed_today or no_walkin_hours_today. */
            'remote_refusal_reason_code' => $p['remote_refusal_reason_code'],
            /** The listed fee in minor units. Nothing is charged here. */
            'fee' => $p['fee'],
            /** @var array{min_minutes: int|null, max_minutes: int|null, is_estimate: true, may_not_be_served_today: bool|null, wait_status: string}|null Present only when the app can register you now. No place in line: that would count the queue. */
            'estimate' => $p['estimate'],
            /** True when the range reaches closing time: joining then needs `acknowledge_late_risk` (E1). */
            'requires_late_risk_acknowledgement' => (bool) $p['requires_late_risk_acknowledgement'],
        ];
    }
}
