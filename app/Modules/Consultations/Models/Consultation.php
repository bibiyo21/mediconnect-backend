<?php

namespace App\Modules\Consultations\Models;

use App\Modules\Consultations\Enums\ConsultationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A doctor-patient encounter (database-schema.md §10) from exactly one source: a booked appointment or a called walk-in.
 * A doctor has at most one IN_PROGRESS consultation (partial unique index), which drives derived BUSY.
 * patient_profile_id is null only for a guest walk-in.
 *
 * @property string $id
 * @property string|null $appointment_id
 * @property string|null $walkin_queue_entry_id
 * @property string $doctor_profile_id
 * @property string|null $patient_profile_id
 * @property string|null $clinic_location_id
 * @property string $consultation_type
 * @property ConsultationStatus $status
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $ended_at
 * @property int|null $duration_seconds
 * @property string|null $video_room_id
 */
class Consultation extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ConsultationStatus::class,
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /** @return HasMany<ConsultationParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ConsultationParticipant::class);
    }
}
