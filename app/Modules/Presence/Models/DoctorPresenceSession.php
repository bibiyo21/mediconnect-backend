<?php

namespace App\Modules\Presence\Models;

use App\Modules\Presence\Enums\PresenceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One stretch of a doctor being at a clinic location, or online (database-schema.md §7).
 * At most one is open per doctor (partial unique index). Open means checked_out_at is null.
 *
 * @property string $id
 * @property string $doctor_profile_id
 * @property string|null $clinic_location_id
 * @property string $session_kind
 * @property PresenceStatus $current_status
 * @property CarbonImmutable $checked_in_at
 * @property string $checked_in_by
 * @property CarbonImmutable|null $checked_out_at
 * @property string|null $checked_out_by
 * @property string|null $checkout_reason
 * @property string|null $checked_out_method
 * @property CarbonImmutable|null $current_break_expected_end_at
 * @property array<string, mixed>|null $location_evidence
 * @property int $version
 */
class DoctorPresenceSession extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $attributes = ['version' => 1];

    /** @var list<string> Optional location evidence (D-26) is never returned from the model. */
    protected $hidden = ['location_evidence'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'current_status' => PresenceStatus::class,
            'checked_in_at' => 'immutable_datetime',
            'checked_out_at' => 'immutable_datetime',
            'current_break_expected_end_at' => 'immutable_datetime',
            'location_evidence' => 'array',
            'version' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->checked_out_at === null;
    }

    /** @return HasMany<DoctorPresenceEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(DoctorPresenceEvent::class, 'doctor_presence_session_id');
    }
}
