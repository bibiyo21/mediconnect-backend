<?php

namespace App\Modules\Patients\Models;

use App\Modules\Patients\Enums\DependentRelationship;
use App\Modules\Patients\Enums\GuardianAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guardian's link to a person whose care they manage (CLAUDE.md §4.3).
 *
 * @property string $id
 * @property string $guardian_patient_profile_id
 * @property string $dependent_patient_profile_id
 * @property DependentRelationship $relationship
 * @property GuardianAuthorization $authorization_status
 * @property CarbonImmutable|null $authorized_at
 * @property CarbonImmutable|null $revoked_at
 * @property int|null $consent_record_id
 * @property-read PatientProfile $dependent
 */
class Dependent extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'relationship' => DependentRelationship::class,
            'authorization_status' => GuardianAuthorization::class,
            'authorized_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PatientProfile, $this> */
    public function dependent(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'dependent_patient_profile_id');
    }

    /** @return BelongsTo<PatientProfile, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(PatientProfile::class, 'guardian_patient_profile_id');
    }

    /**
     * @param  Builder<Dependent>  $query
     * @return Builder<Dependent>
     */
    public function scopeAuthorized(Builder $query): Builder
    {
        return $query->where('authorization_status', GuardianAuthorization::Authorized->value);
    }
}
