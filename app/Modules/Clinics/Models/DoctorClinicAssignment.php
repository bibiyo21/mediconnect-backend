<?php

namespace App\Modules\Clinics\Models;

use App\Modules\Clinics\Enums\AssignmentStatus;
use App\Modules\Doctors\Models\DoctorProfile;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a doctor practises (database-schema.md §4). Check-in (Phase 5) and
 * in-person services require an ACTIVE assignment.
 *
 * @property string $id
 * @property string $doctor_profile_id
 * @property string $clinic_location_id
 * @property AssignmentStatus $status
 * @property string $requested_by
 * @property CarbonImmutable|null $effective_from
 * @property CarbonImmutable|null $effective_until
 * @property string|null $end_reason
 * @property array<string, mixed>|null $walkin_policy
 * @property CarbonImmutable $created_at
 * @property-read DoctorProfile $doctorProfile
 * @property-read ClinicLocation $location
 */
class DoctorClinicAssignment extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'PENDING'];

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'effective_from' => 'immutable_datetime',
            'effective_until' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'walkin_policy' => 'array',
        ];
    }

    /** @return BelongsTo<DoctorProfile, $this> */
    public function doctorProfile(): BelongsTo
    {
        return $this->belongsTo(DoctorProfile::class);
    }

    /** @return BelongsTo<ClinicLocation, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(ClinicLocation::class, 'clinic_location_id');
    }

    /** True when the doctor has an ACTIVE assignment at the location. */
    public static function activeAt(string $doctorProfileId, string $clinicLocationId): bool
    {
        return self::where('doctor_profile_id', $doctorProfileId)->where('clinic_location_id', $clinicLocationId)->where('status', AssignmentStatus::Active->value)->exists();
    }
}
