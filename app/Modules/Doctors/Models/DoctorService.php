<?php

namespace App\Modules\Doctors\Models;

use App\Modules\Clinics\Models\ClinicLocation;
use App\Modules\Doctors\Enums\ConsultationType;
use App\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A consultation a doctor offers, at one location or online, with its fee
 * (database-schema.md §5). Rows are effective-dated: a change ends this row
 * and starts a replacement, so a booking can keep the fee it was made at.
 *
 * @property string $id
 * @property string $doctor_profile_id
 * @property string|null $clinic_location_id
 * @property string|null $clinic_service_id
 * @property string $name
 * @property ConsultationType $consultation_type
 * @property bool $is_follow_up
 * @property int $duration_minutes
 * @property int $buffer_minutes
 * @property int $fee_amount
 * @property string $currency
 * @property bool $is_hmo_eligible
 * @property string $status
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property string|null $replaces_id
 * @property-read ClinicLocation|null $location
 */
class DoctorService extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'ACTIVE', 'currency' => 'PHP', 'buffer_minutes' => 0, 'is_follow_up' => false, 'is_hmo_eligible' => false];

    protected function casts(): array
    {
        return [
            'consultation_type' => ConsultationType::class,
            'is_follow_up' => 'boolean',
            'is_hmo_eligible' => 'boolean',
            'duration_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'fee_amount' => 'integer',
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ClinicLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ClinicLocation::class, 'clinic_location_id');
    }

    public function fee(): Money
    {
        return Money::of($this->fee_amount, $this->currency);
    }
}
