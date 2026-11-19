<?php

namespace App\Modules\Doctors\Models;

use App\Modules\Doctors\Enums\OnboardingStatus;
use App\Modules\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A clinician's professional profile (CLAUDE.md §5.1). A user may also keep a
 * separate patient profile (D-16).
 *
 * @property string $id
 * @property string $user_id
 * @property string $display_name
 * @property string|null $professional_title
 * @property string|null $biography
 * @property string|null $prc_license_number_encrypted
 * @property string|null $prc_license_number_hash
 * @property string $license_verification_status
 * @property OnboardingStatus $onboarding_status
 * @property CarbonImmutable|null $verified_at
 * @property string|null $medical_school
 * @property int|null $years_experience
 * @property string|null $gender
 * @property int $profile_completeness
 * @property-read User $user
 */
class DoctorProfile extends Model
{
    use HasUuids;

    protected $fillable = ['display_name', 'professional_title', 'biography', 'medical_school', 'years_experience', 'gender'];

    protected $hidden = ['prc_license_number_encrypted', 'prc_license_number_hash'];

    protected $attributes = ['onboarding_status' => 'REGISTERED', 'license_verification_status' => 'NOT_VERIFIED'];

    protected function casts(): array
    {
        return [
            'prc_license_number_encrypted' => 'encrypted',
            'onboarding_status' => OnboardingStatus::class,
            'verified_at' => 'immutable_datetime',
            'years_experience' => 'integer',
            'profile_completeness' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<DoctorSpecialty, $this> */
    public function specialties(): HasMany
    {
        return $this->hasMany(DoctorSpecialty::class);
    }

    /** @return HasMany<DoctorCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(DoctorCredential::class)->orderBy('created_at');
    }

    /** @return HasMany<DoctorEducation, $this> */
    public function education(): HasMany
    {
        return $this->hasMany(DoctorEducation::class)->orderByDesc('year_completed');
    }

    /** @return HasMany<DoctorExperience, $this> */
    public function experience(): HasMany
    {
        return $this->hasMany(DoctorExperience::class)->orderByDesc('started_on');
    }

    /** @return list<string> */
    public function languageCodes(): array
    {
        return array_values(DB::table('doctor_languages')->where('doctor_profile_id', $this->id)->orderBy('language_code')->pluck('language_code')->all());
    }
}
