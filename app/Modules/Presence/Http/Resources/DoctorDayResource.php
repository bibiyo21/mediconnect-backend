<?php

namespace App\Modules\Presence\Http\Resources;

use App\Modules\Scheduling\DTOs\DoctorDayShift;
use App\Modules\Scheduling\DTOs\TimeWindow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The doctor's day: an explicit whitelist (increment 5.9-B, D-B). Each key is written out here with its type, so a field added to the
 * reader never reaches the client by accident. Times are UTC. No patient data, no appointment ids, no break labels or reasons.
 *
 * @property array{local_date: string, timezone: string, locations: list<array<string, mixed>>} $resource
 */
final class DoctorDayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            /** @var string The market date the request was for, Y-m-d (today in the market zone). */
            'local_date' => $this->resource['local_date'],
            /** @var string The market zone. */
            'timezone' => $this->resource['timezone'],
            /** @var list<array{clinic_location_id: string, location_name: string, local_date: string, timezone: string, shifts: list<array{starts_at: string, ends_at: string, service_mode: 'APPOINTMENT'|'WALK_IN'|'BOTH'}>, scheduled_breaks: list<array{id: string, starts_at: string, ends_at: string}>, check_in: array{allowed: bool, reason_code?: 'doctor_not_active'|'location_not_active'|'no_assignment'|'no_service_mode'|'clinic_closed'|'closed_holiday'|'outside_hours', opens_at?: string}}> One entry per ACTIVE assignment. */
            'locations' => array_map(fn (array $location) => [
                'clinic_location_id' => $location['clinic_location_id'],
                'location_name' => $location['location_name'],
                /** The date this location's day is read for (today in its own zone), and the zone. */
                'local_date' => $location['local_date'],
                'timezone' => $location['timezone'],
                /** The working spans today, after exceptions and special hours: one per shift, every service mode. */
                'shifts' => array_map(fn (DoctorDayShift $s) => self::span($s) + ['service_mode' => $s->serviceMode], $location['shifts']),
                /** The scheduled breaks today; `id` is the break to pass as scheduled_break_id. */
                'scheduled_breaks' => array_map(fn (TimeWindow $w) => ['id' => (string) $w->id] + self::span($w), $location['scheduled_breaks']),
                /** Advisory: the same rules the check-in applies. Check-in still re-checks. `reason_code` and `opens_at` only when refused and known. */
                'check_in' => $this->checkIn($location['check_in']),
            ], $this->resource['locations']),
        ];
    }

    /** @return array{starts_at: string, ends_at: string} */
    private static function span(DoctorDayShift|TimeWindow $window): array
    {
        return ['starts_at' => $window->startsAt->toIso8601ZuluString(), 'ends_at' => $window->endsAt->toIso8601ZuluString()];
    }

    /**
     * @param  array<string, mixed>  $check
     * @return array{allowed: bool, reason_code?: string, opens_at?: string}
     */
    private function checkIn(array $check): array
    {
        $out = ['allowed' => (bool) $check['allowed']];
        if (isset($check['reason_code'])) {
            $out['reason_code'] = (string) $check['reason_code'];
        }
        if (isset($check['opens_at'])) {
            $out['opens_at'] = $check['opens_at']->toIso8601ZuluString();
        }

        return $out;
    }
}
