<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Models\DoctorScheduleBreak;
use App\Modules\Scheduling\Models\DoctorScheduleShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A weekly schedule as the doctor or clinic sees it (times are local to `timezone`).
 *
 * @mixin DoctorSchedule
 */
final class ScheduleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DoctorSchedule $s */
        $s = $this->resource;

        return [
            'id' => $s->id,
            'doctor_profile_id' => $s->doctor_profile_id,
            'name' => $s->name,
            'status' => $s->status,
            /** Send back as If-Match when changing the schedule. */
            'version' => $s->version,
            'effective_from' => $s->getAttribute('effective_from')?->toDateString(),
            'effective_until' => $s->getAttribute('effective_until')?->toDateString(),
            'shifts' => $s->shifts->sortBy([['day_of_week', 'asc'], ['starts_at_local', 'asc']])->values()->map(fn (DoctorScheduleShift $x) => [
                'id' => $x->id,
                'day_of_week' => $x->day_of_week,
                'doctor_service_mode_id' => $x->doctor_service_mode_id,
                // null for online shifts
                'clinic_location_id' => $x->clinic_location_id,
                'starts_at' => substr($x->starts_at_local, 0, 5),
                'ends_at' => substr($x->ends_at_local, 0, 5),
                'timezone' => $x->timezone,
                'slot_duration_minutes' => $x->slot_duration_minutes,
                'buffer_minutes' => $x->buffer_minutes,
                'max_appointments' => $x->max_appointments,
                'walkin_capacity' => $x->walkin_capacity,
                'breaks' => $x->breaks->sortBy('starts_at_local')->values()->map(fn (DoctorScheduleBreak $b) => [
                    'starts_at' => substr($b->starts_at_local, 0, 5), 'ends_at' => substr($b->ends_at_local, 0, 5), 'label' => $b->label,
                ])->all(),
            ])->all(),
        ];
    }
}
