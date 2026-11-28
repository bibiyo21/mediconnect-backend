<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Contracts\CheckOutAppointments;
use App\Modules\Appointments\DTOs\AppointmentRef;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CheckOutAppointmentsService implements CheckOutAppointments
{
    public function remaining(string $doctorProfileId, string $locationId, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): array
    {
        return array_values($this->window($doctorProfileId, $locationId, $fromUtc, $toUtc)
            ->orderBy('starts_at')
            ->get(['id', 'starts_at'])
            ->map(fn (Appointment $a) => new AppointmentRef((string) $a->id, CarbonImmutable::instance($a->starts_at)->utc()))
            ->all());
    }

    public function flagForCheckOut(string $doctorProfileId, string $locationId, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): array
    {
        $ids = $this->window($doctorProfileId, $locationId, $fromUtc, $toUtc)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($ids === []) {
            return ['flagged' => 0, 'tasks_created' => 0];
        }

        Appointment::query()->whereIn('id', $ids)->update(['needs_rescheduling' => true, 'updated_at' => $fromUtc]);

        // rescheduling_tasks_one_open (a partial unique index on appointment_id) makes a repeat insert a no-op.
        $created = DB::table('rescheduling_tasks')->insertOrIgnore(array_map(fn (string $id) => [
            'id' => (string) Str::uuid7(),
            'appointment_id' => $id,
            'reason' => 'DOCTOR_CHECKOUT',
            'status' => 'OPEN',
            'assigned_to' => null,
            'resolution' => null,
            'resolved_at' => null,
            'created_at' => $fromUtc,
            'updated_at' => $fromUtc,
        ], $ids));

        return ['flagged' => count($ids), 'tasks_created' => $created];
    }

    /**
     * Live appointments of the doctor at the location that still occupy the rest of the local day from $fromUtc.
     *
     * @return Builder<Appointment>
     */
    private function window(string $doctorProfileId, string $locationId, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): Builder
    {
        return Appointment::query()
            ->where('doctor_profile_id', $doctorProfileId)
            ->where('clinic_location_id', $locationId)
            ->whereIn('status', array_map(fn (AppointmentStatus $s) => $s->value, AppointmentStatus::live()))
            ->where('ends_at', '>', $fromUtc)
            ->where('starts_at', '<', $toUtc);
    }
}
