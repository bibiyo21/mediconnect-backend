<?php

namespace App\Modules\Doctors\Models;

use App\Modules\Specialties\Models\Specialty;
use App\Modules\Specialties\Models\Subspecialty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $doctor_profile_id
 * @property string $specialty_id
 * @property string|null $subspecialty_id
 * @property bool $is_primary
 * @property bool $board_certified
 * @property string $verification_status SELF_REPORTED until a reviewer confirms it (D-40)
 * @property CarbonImmutable|null $confirmed_at
 * @property-read Specialty $specialty
 * @property-read Subspecialty|null $subspecialty
 */
class DoctorSpecialty extends Model
{
    use HasUuids;

    protected $fillable = ['specialty_id', 'subspecialty_id', 'is_primary', 'board_certified'];

    protected $attributes = ['verification_status' => 'SELF_REPORTED'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'board_certified' => 'boolean', 'confirmed_at' => 'immutable_datetime'];
    }

    public function isConfirmed(): bool
    {
        return $this->verification_status === 'CONFIRMED';
    }

    /**
     * Read-only relationship across modules (architecture.md §6.3 rule 2).
     *
     * @return BelongsTo<Specialty, $this>
     */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

    /** @return BelongsTo<Subspecialty, $this> */
    public function subspecialty(): BelongsTo
    {
        return $this->belongsTo(Subspecialty::class);
    }
}
