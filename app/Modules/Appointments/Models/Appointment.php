<?php

namespace App\Modules\Appointments\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An appointment (database-schema.md §9). Structure only until Phase 6 booking; the overlap constraint protects it from day one. Status changes go through one state machine (Phase 6).
 *
 * @property string $id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $reference_code
 * @property string $patient_profile_id
 * @property string $doctor_profile_id
 * @property string|null $clinic_location_id
 * @property string $status
 * @property string $payer_type
 * @property int $fee_amount
 * @property string $currency
 */
class Appointment extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime',
            'rescheduled_from_starts_at' => 'immutable_datetime',
            'policy_snapshot' => 'array',
            'needs_rescheduling' => 'boolean',
            'fee_amount' => 'integer',
            'reschedule_count' => 'integer',
            'version' => 'integer',
        ];
    }
}
