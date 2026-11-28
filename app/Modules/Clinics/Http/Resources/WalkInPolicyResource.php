<?php

namespace App\Modules\Clinics\Http\Resources;

use App\Modules\Clinics\DTOs\WalkInPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A doctor's walk-in settings at one location, with the capacity today's schedule gives.
 *
 * @property array{assignment_id: string, clinic_location_id: string, doctor_profile_id: string, policy: WalkInPolicy, derived_capacity_today: int, local_date: string, timezone: string} $resource
 */
final class WalkInPolicyResource extends JsonResource
{
    /** @return array{assignment_id: string, clinic_location_id: string, doctor_profile_id: string, allow_patient_app_registration: bool, cutoff_minutes_before_close: int, number_prefix: string|null, capacity_mode: string, recall_limit: int, auto_checkout_grace_minutes: int, derived_capacity_today: int, local_date: string, timezone: string} */
    public function toArray(Request $request): array
    {
        /** @var WalkInPolicy $policy */
        $policy = $this->resource['policy'];

        return [
            'assignment_id' => $this->resource['assignment_id'],
            'clinic_location_id' => $this->resource['clinic_location_id'],
            'doctor_profile_id' => $this->resource['doctor_profile_id'],
            'allow_patient_app_registration' => (bool) $policy->allowPatientAppRegistration,
            'cutoff_minutes_before_close' => (int) $policy->cutoffMinutesBeforeClose,
            'number_prefix' => $policy->numberPrefix,
            'capacity_mode' => $policy->capacityMode->value,
            /** How many times a called patient may be recalled before staff may mark a no-show. */
            'recall_limit' => (int) $policy->recallLimit,
            /** Minutes after closing before an open session of this doctor here is checked out automatically (0 to 720). */
            'auto_checkout_grace_minutes' => (int) $policy->autoCheckoutGraceMinutes,
            /** The capacity today's schedule gives this doctor here (the sum over today's walk-in shifts). With UNLIMITED it is informational: the queue has no cap. */
            'derived_capacity_today' => (int) $this->resource['derived_capacity_today'],
            /** The location's local date the capacity is for (Y-m-d). */
            'local_date' => (string) $this->resource['local_date'],
            'timezone' => (string) $this->resource['timezone'],
        ];
    }
}
